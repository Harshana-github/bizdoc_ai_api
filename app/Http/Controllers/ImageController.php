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
        $this->endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$this->geminiKey}";
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
                $fileName = time() . '_' . uniqid() . '_' . $file->getClientOriginalName();
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

            $images = Image::whereIn('id', $ids)
                ->orderByRaw('FIELD(id, ' . implode(',', $ids) . ')')
                ->get();

            if ($images->isEmpty()) {
                return response()->json([
                    "success" => false,
                    "message" => "No valid images found"
                ], 422);
            }

            $parts = [];
            $parts[] = [
                "text" => <<<TEXT
You are analyzing property inspection photos. This includes Cleaning, Repairs, and Part Replacements.

Return only a JSON array.

Expected format:
[
  {"image_index": 0, "category": "窓の鍵交換", "stage": "before", "quality_score": 8}
]

Rules:
- stage must be strictly "before" or "after"
- category must be short, specific, and entirely in Japanese
- category must describe the exact task shown
- examples: "窓の鍵交換", "排水口カバー交換", "テープ補修", "キッチン清掃"
- related BEFORE and AFTER images must use the exact same category text
- if image is blurred, dark, duplicate, unrelated, or unusable, set quality_score to 0
- quality_score must be an integer between 0 and 10
- image_index must match the order of the provided images starting from 0

Return JSON only. No markdown.
TEXT
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

                $optimized = $this->prepareImageForGemini($fullPath);

                if (!$optimized) {
                    Log::warning('Image optimize failed', [
                        'image_id' => $img->id,
                        'file_path' => $img->file_path
                    ]);
                    continue;
                }

                $imageMap[$validIndex] = $img;

                $parts[] = [
                    "inline_data" => [
                        "mime_type" => $optimized['mime_type'],
                        "data" => $optimized['base64']
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
                ],
                "generationConfig" => [
                    "temperature" => 0.2,
                    "topP" => 0.8,
                    "response_mime_type" => "application/json",
                    "response_schema" => [
                        "type" => "ARRAY",
                        "items" => [
                            "type" => "OBJECT",
                            "properties" => [
                                "image_index" => [
                                    "type" => "INTEGER"
                                ],
                                "category" => [
                                    "type" => "STRING"
                                ],
                                "stage" => [
                                    "type" => "STRING",
                                    "enum" => ["before", "after"]
                                ],
                                "quality_score" => [
                                    "type" => "INTEGER"
                                ]
                            ],
                            "required" => [
                                "image_index",
                                "category",
                                "stage",
                                "quality_score"
                            ]
                        ]
                    ]
                ]
            ];

            $response = Http::timeout(120)
                ->acceptJson()
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

            $text = data_get($response->json(), 'candidates.0.content.parts.0.text', '');
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
                $category = $this->normalizeCategory($item['category'] ?? '');
                $stage = strtolower(trim($item['stage'] ?? ''));
                $score = isset($item['quality_score']) ? (int) $item['quality_score'] : 0;

                if ($index === null || !isset($imageMap[$index])) {
                    continue;
                }

                if (!in_array($stage, ['before', 'after'], true)) {
                    continue;
                }

                if ($category === '') {
                    continue;
                }

                if ($score <= 0) {
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

            ksort($report);

            foreach ($report as $category => $data) {
                $description = $this->generateInspectionDescription(
                    $data['before']['file_path'],
                    $data['after']['file_path'],
                    $category
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

    private function generateInspectionDescription($beforePath, $afterPath, $category = '')
    {
        try {
            if (
                !Storage::disk('public')->exists($beforePath) ||
                !Storage::disk('public')->exists($afterPath)
            ) {
                return "作業による改善が確認されました。";
            }

            $fullBeforePath = storage_path('app/public/' . $beforePath);
            $fullAfterPath = storage_path('app/public/' . $afterPath);

            $beforePrepared = $this->prepareImageForGemini($fullBeforePath, 1024, 78);
            $afterPrepared = $this->prepareImageForGemini($fullAfterPath, 1024, 78);

            if (!$beforePrepared || !$afterPrepared) {
                return "作業による改善が確認されました。";
            }

            $prompt = <<<TEXT
Compare these two property maintenance images.

Image 1 = BEFORE
Image 2 = AFTER

Task category: {$category}

Describe the improvement, repair, replacement, or cleaning work that was completed.
Write only one short professional sentence.
The response must be entirely in Japanese.
TEXT;

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            [
                                "text" => $prompt
                            ],
                            [
                                "inline_data" => [
                                    "mime_type" => $beforePrepared['mime_type'],
                                    "data" => $beforePrepared['base64']
                                ]
                            ],
                            [
                                "inline_data" => [
                                    "mime_type" => $afterPrepared['mime_type'],
                                    "data" => $afterPrepared['base64']
                                ]
                            ]
                        ]
                    ]
                ],
                "generationConfig" => [
                    "temperature" => 0.2
                ]
            ];

            $response = Http::timeout(60)
                ->acceptJson()
                ->withHeaders([
                    "Content-Type" => "application/json"
                ])
                ->post($this->endpoint, $payload);

            if (!$response->successful()) {
                Log::warning('Gemini description failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'category' => $category
                ]);

                return "作業が正常に完了しました。";
            }

            $result = trim(data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

            return $result !== '' ? $result : "作業が完了しました。";

        } catch (\Throwable $e) {
            Log::warning('generateInspectionDescription exception', [
                'message' => $e->getMessage(),
                'category' => $category
            ]);

            return "作業が完了しました。";
        }
    }

    private function normalizeCategory(string $category): string
    {
        $category = trim($category);

        if ($category === '') {
            return '';
        }

        $category = preg_replace('/\s+/u', '', $category);

        $replacements = [
            'の交換' => '交換',
            'の修理' => '修理',
            'の清掃' => '清掃',
            '交換作業' => '交換',
            '修繕' => '修理',
            '補修作業' => '補修',
        ];

        $category = strtr($category, $replacements);

        return $category;
    }

    private function prepareImageForGemini(string $fullPath, int $maxWidth = 1280, int $jpegQuality = 80): ?array
    {
        try {
            if (!file_exists($fullPath)) {
                return null;
            }

            $imageInfo = @getimagesize($fullPath);

            if (!$imageInfo || empty($imageInfo['mime'])) {
                return null;
            }

            $mime = $imageInfo['mime'];
            $source = null;

            switch ($mime) {
                case 'image/jpeg':
                case 'image/jpg':
                    $source = @imagecreatefromjpeg($fullPath);
                    break;

                case 'image/png':
                    $source = @imagecreatefrompng($fullPath);
                    break;

                case 'image/gif':
                    $source = @imagecreatefromgif($fullPath);
                    break;

                case 'image/webp':
                    if (function_exists('imagecreatefromwebp')) {
                        $source = @imagecreatefromwebp($fullPath);
                    }
                    break;

                default:
                    return null;
            }

            if (!$source) {
                return null;
            }

            if (($mime === 'image/jpeg' || $mime === 'image/jpg') && function_exists('exif_read_data')) {
                $exif = @exif_read_data($fullPath);

                if (!empty($exif['Orientation'])) {
                    switch ((int) $exif['Orientation']) {
                        case 3:
                            $source = imagerotate($source, 180, 0);
                            break;
                        case 6:
                            $source = imagerotate($source, -90, 0);
                            break;
                        case 8:
                            $source = imagerotate($source, 90, 0);
                            break;
                    }
                }
            }

            $originalWidth = imagesx($source);
            $originalHeight = imagesy($source);

            if ($originalWidth <= 0 || $originalHeight <= 0) {
                imagedestroy($source);
                return null;
            }

            $newWidth = $originalWidth;
            $newHeight = $originalHeight;

            if ($originalWidth > $maxWidth) {
                $ratio = $maxWidth / $originalWidth;
                $newWidth = (int) round($originalWidth * $ratio);
                $newHeight = (int) round($originalHeight * $ratio);
            }

            $canvas = imagecreatetruecolor($newWidth, $newHeight);

            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);

            imagecopyresampled(
                $canvas,
                $source,
                0,
                0,
                0,
                0,
                $newWidth,
                $newHeight,
                $originalWidth,
                $originalHeight
            );

            ob_start();
            imagejpeg($canvas, null, $jpegQuality);
            $binary = ob_get_clean();

            imagedestroy($source);
            imagedestroy($canvas);

            if (!$binary) {
                return null;
            }

            return [
                'mime_type' => 'image/jpeg',
                'base64' => base64_encode($binary),
            ];

        } catch (\Throwable $e) {
            Log::warning('prepareImageForGemini exception', [
                'path' => $fullPath,
                'message' => $e->getMessage()
            ]);

            return null;
        }
    }
}