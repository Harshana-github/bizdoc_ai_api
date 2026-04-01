<?php

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    private $geminiKey;
    private $endpoint;

    public function __construct()
    {
        $this->geminiKey = config('gemini.api_key');
        $this->endpoint = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$this->geminiKey}";
    }

    public function index()
    {
        $images = Image::orderBy('created_at', 'desc')->get();

        $images->transform(function ($image) {
            $image->url = asset('storage/' . $image->file_path);
            return $image;
        });

        return response()->json([
            'success' => true,
            'data' => $images
        ], 200);
    }

    public function upload(Request $request)
    {
        $request->validate([
            'images' => 'required|array',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        $uploadedImages = [];

        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                $fileName = time() . '_' . $file->getClientOriginalName();
                $filePath = $file->storeAs('uploads/images', $fileName, 'public');

                $image = Image::create([
                    'file_name' => $fileName,
                    'file_path' => $filePath,
                ]);

                $uploadedImages[] = $image;
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Images uploaded successfully',
            'data' => $uploadedImages
        ], 201);
    }

    public function deleteMultiple(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:images,id'
        ]);

        $images = Image::whereIn('id', $request->ids)->get();

        foreach ($images as $image) {
            if (Storage::disk('public')->exists($image->file_path)) {
                Storage::disk('public')->delete($image->file_path);
            }
            $image->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Selected images deleted successfully.'
        ], 200);
    }

    public function generateReport(Request $request)
    {
        set_time_limit(180);

        $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'exists:images,id'
        ]);

        try {
            $ids = array_map('intval', $request->ids);

            // frontend order preserve කරන්න
            $images = Image::whereIn('id', $ids)->get()->sortBy(function ($img) use ($ids) {
                return array_search($img->id, $ids);
            })->values();

            if ($images->isEmpty()) {
                return response()->json([
                    "success" => false,
                    "message" => "No valid images found"
                ], 422);
            }

            $parts = [];
            $parts[] = [
                "text" => '
You are analyzing property inspection photos. This includes Cleaning, Repairs, and Part Replacements.

For each image return JSON:
[
  {"image_index": 0, "category": "窓の鍵交換", "stage": "before", "quality_score": 8}
]

Rules:
- stage must be strictly "before" or "after"
- category must be in Japanese
- category should identify the specific task or item shown
- related BEFORE and AFTER images should use the same category name as much as possible
- quality_score must be 0 to 10
- if blurred, dark, duplicate, irrelevant, or unusable, set quality_score to 0
- image_index must match the order of images provided, starting from 0

Return ONLY valid JSON. Do not wrap in markdown.
'
            ];

            $imageMap = [];
            $validIndex = 0;

            foreach ($images as $img) {
                if (!Storage::disk('public')->exists($img->file_path)) {
                    Log::warning('Image file missing', [
                        'image_id' => $img->id,
                        'file_path' => $img->file_path
                    ]);
                    continue;
                }

                $fullPath = storage_path('app/public/' . $img->file_path);

                $fileContent = @file_get_contents($fullPath);
                $mimeType = @mime_content_type($fullPath);

                if ($fileContent === false || empty($mimeType)) {
                    Log::warning('Image read failed', [
                        'image_id' => $img->id,
                        'file_path' => $img->file_path
                    ]);
                    continue;
                }

                $imageMap[$validIndex] = $img;

                $parts[] = [
                    "inline_data" => [
                        "mime_type" => $mimeType,
                        "data" => base64_encode($fileContent)
                    ]
                ];

                $validIndex++;
            }

            if ($validIndex === 0) {
                return response()->json([
                    "success" => false,
                    "message" => "No readable images found"
                ], 422);
            }

            Log::info('Gemini generateReport input summary', [
                'requested_ids' => $ids,
                'db_ids_order' => $images->pluck('id')->toArray(),
                'valid_image_map_ids' => collect($imageMap)->pluck('id')->toArray(),
                'valid_image_count' => $validIndex,
            ]);

            $payload = [
                "contents" => [
                    [
                        "parts" => $parts
                    ]
                ]
            ];

            $response = Http::timeout(120)
                ->withHeaders([
                    "Content-Type" => "application/json"
                ])
                ->post($this->endpoint, $payload);

            if (!$response->successful()) {
                Log::error('Gemini classification failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);

                return response()->json([
                    "success" => false,
                    "error" => "Gemini request failed",
                    "details" => $response->body()
                ], 500);
            }

            $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $text = trim($text);
            $text = preg_replace('/^```json\s*/i', '', $text);
            $text = preg_replace('/^```\s*/', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim($text);

            Log::info('Gemini raw classification output', [
                'text' => $text
            ]);

            $analysis = json_decode($text, true);

            if (!is_array($analysis)) {
                return response()->json([
                    "success" => false,
                    "error" => "Failed to parse Gemini response",
                    "raw" => $text
                ], 500);
            }

            $report = [];

            foreach ($analysis as $item) {
                $index = isset($item['image_index']) ? (int) $item['image_index'] : null;
                $category = trim($item['category'] ?? '');
                $stage = strtolower(trim($item['stage'] ?? ''));
                $score = isset($item['quality_score']) ? (int) $item['quality_score'] : 0;

                if ($index === null || !isset($imageMap[$index])) {
                    continue;
                }

                if (!in_array($stage, ['before', 'after'], true)) {
                    continue;
                }

                if ($category === '' || $score <= 0) {
                    continue;
                }

                if ($score > 10) {
                    $score = 10;
                }

                $image = $imageMap[$index];

                if (
                    !isset($report[$category][$stage]) ||
                    $report[$category][$stage]['score'] < $score
                ) {
                    $report[$category][$stage] = [
                        "id" => $image->id,
                        "url" => asset('storage/' . $image->file_path),
                        "file_path" => $image->file_path,
                        "score" => $score
                    ];
                }
            }

            foreach ($report as $category => $data) {
                if (!isset($data['before']) || !isset($data['after'])) {
                    unset($report[$category]);
                }
            }

            foreach ($report as $category => $data) {
                $description = $this->generateInspectionDescription(
                    $data['before']['file_path'],
                    $data['after']['file_path']
                );

                $report[$category]['description'] = $description;
            }

            foreach ($report as $category => $data) {
                unset($report[$category]['before']['score']);
                unset($report[$category]['after']['score']);
                unset($report[$category]['before']['file_path']);
                unset($report[$category]['after']['file_path']);
            }

            return response()->json([
                "success" => true,
                "data" => $report
            ], 200);
        } catch (\Throwable $e) {
            Log::error('generateReport exception', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return response()->json([
                "success" => false,
                "error" => "Internal server error",
                "message" => $e->getMessage(),
                "line" => $e->getLine()
            ], 500);
        }
    }

    private function generateInspectionDescription($beforePath, $afterPath)
    {
        try {
            if (!Storage::disk('public')->exists($beforePath) || !Storage::disk('public')->exists($afterPath)) {
                return "作業による改善が確認されました。";
            }

            $fullBeforePath = storage_path('app/public/' . $beforePath);
            $fullAfterPath = storage_path('app/public/' . $afterPath);

            $beforeBase64 = base64_encode(file_get_contents($fullBeforePath));
            $beforeMime = mime_content_type($fullBeforePath);

            $afterBase64 = base64_encode(file_get_contents($fullAfterPath));
            $afterMime = mime_content_type($fullAfterPath);

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            [
                                "text" => '
Compare these two property maintenance images.
Image 1 = BEFORE
Image 2 = AFTER
Describe the improvement, repair, or replacement that was done in one short, professional sentence.
IMPORTANT: The response MUST be written entirely in Japanese.
'
                            ],
                            [
                                "inline_data" => [
                                    "mime_type" => $beforeMime,
                                    "data" => $beforeBase64
                                ]
                            ],
                            [
                                "inline_data" => [
                                    "mime_type" => $afterMime,
                                    "data" => $afterBase64
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $response = Http::timeout(60)
                ->withHeaders([
                    "Content-Type" => "application/json"
                ])
                ->post($this->endpoint, $payload);

            if (!$response->successful()) {
                Log::warning('Gemini description failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return "作業が正常に完了しました。";
            }

            return trim($response['candidates'][0]['content']['parts'][0]['text'] ?? "作業が完了しました。");
        } catch (\Throwable $e) {
            Log::warning('generateInspectionDescription exception', [
                'message' => $e->getMessage()
            ]);
            return "作業が完了しました。";
        }
    }
}
