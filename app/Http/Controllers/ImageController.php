<?php

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

    // Fetch all images
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

    // Handle multiple image uploads
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

    // Handle multiple image deletions
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
        set_time_limit(120);

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:images,id'
        ]);

        try {
            $images = Image::whereIn('id', $request->ids)->get();

            if ($images->isEmpty()) {
                return response()->json(["message" => "No valid images found"], 422);
            }

            /*
            --------------------------------
            Prepare Gemini Classification (Updated for Japanese & Quality check)
            --------------------------------
            */

            $parts = [];
            $parts[] = [
                "text" => "
                You are analyzing cleaning inspection photos.

                For each image return JSON:
                [
                  {\"image_index\": 0, \"room_type\": \"リビングルーム\", \"stage\": \"before\", \"quality_score\": 8}
                ]

                Rules:
                - stage must be strictly 'before' or 'after'.
                - room_type MUST be categorized and translated into Japanese (e.g., リビングルーム, キッチン, バスルーム, トイレ, 寝室).
                - quality_score (0-10) indicates image clarity and usefulness.
                - CRITICAL: Give a quality_score of 0 if the image is highly blurred, completely dark, irrelevant to cleaning, or a duplicate of a better image.
                - image_index must match the order of images provided, starting from 0.

                Return ONLY valid JSON. Do not wrap in markdown tags like ```json.
                "
            ];

            $imageMap = [];
            $validIndex = 0;

            foreach ($images as $img) {
                if (Storage::disk('public')->exists($img->file_path)) {
                    $imageMap[$validIndex] = $img;

                    $fullPath = storage_path('app/public/' . $img->file_path);
                    $fileContent = file_get_contents($fullPath);
                    $mimeType = mime_content_type($fullPath);
                    $base64 = base64_encode($fileContent);

                    $parts[] = [
                        "inline_data" => [
                            "mime_type" => $mimeType,
                            "data" => $base64
                        ]
                    ];

                    $validIndex++;
                }
            }

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
                return response()->json([
                    "error" => "Gemini request failed",
                    "details" => $response->body()
                ], 500);
            }

            $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';
            $text = trim($text);
            $text = preg_replace('/^```json/', '', $text);
            $text = preg_replace('/```$/', '', $text);

            $analysis = json_decode($text, true);

            if (!$analysis) {
                return response()->json([
                    "error" => "Failed to parse Gemini response",
                    "raw" => $text
                ], 500);
            }

            /*
            --------------------------------
            Select Best Before/After Images
            --------------------------------
            */

            $report = [];

            foreach ($analysis as $item) {
                $index = $item['image_index'];
                // Removed strtolower here because room_type is in Japanese now
                $room = trim($item['room_type']);
                $stage = strtolower(trim($item['stage']));
                $score = (int) $item['quality_score'];

                // Skip blurry, irrelevant, or duplicate images completely
                if ($score <= 0) {
                    continue;
                }

                if (!isset($imageMap[$index])) {
                    continue;
                }

                $image = $imageMap[$index];

                if (
                    !isset($report[$room][$stage]) ||
                    $report[$room][$stage]['score'] < $score
                ) {
                    $report[$room][$stage] = [
                        "id" => $image->id,
                        "url" => asset('storage/' . $image->file_path),
                        "file_path" => $image->file_path,
                        "score" => $score
                    ];
                }
            }

            /*
            --------------------------------
            Remove incomplete rooms
            --------------------------------
            */

            foreach ($report as $room => $data) {
                if (!isset($data['before']) || !isset($data['after'])) {
                    unset($report[$room]);
                }
            }

            /*
            --------------------------------
            Generate AI Cleaning Description (In Japanese)
            --------------------------------
            */

            foreach ($report as $room => $data) {
                $description = $this->generateCleaningDescription(
                    $data['before']['file_path'],
                    $data['after']['file_path']
                );

                $report[$room]['description'] = $description;
            }

            /*
            Remove score and file_path before returning to frontend
            */

            foreach ($report as $room => $data) {
                unset($report[$room]['before']['score']);
                unset($report[$room]['after']['score']);
                unset($report[$room]['before']['file_path']);
                unset($report[$room]['after']['file_path']);
            }

            return response()->json([
                "success" => true,
                "data" => $report
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                "error" => "Internal server error",
                "message" => $e->getMessage(),
                "line" => $e->getLine()
            ], 500);
        }
    }

    private function generateCleaningDescription($beforePath, $afterPath)
    {
        try {
            if (!Storage::disk('public')->exists($beforePath) || !Storage::disk('public')->exists($afterPath)) {
                return "清掃による改善が確認されました。"; // Japanese default message
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
                                "text" => "
                                Compare two cleaning images.
                                Image 1 = BEFORE cleaning
                                Image 2 = AFTER cleaning
                                Describe the cleaning improvement in one short, professional sentence.
                                IMPORTANT: The response MUST be written entirely in Japanese.
                                "
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
                return "清掃が正常に完了しました。"; // Japanese fallback message
            }

            return trim($response['candidates'][0]['content']['parts'][0]['text'] ?? "清掃が完了しました。");
        } catch (\Throwable $e) {
            return "清掃が完了しました。(エラー: " . $e->getMessage() . ")"; // Japanese error fallback
        }
    }
}
