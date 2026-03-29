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
        if (!$this->geminiKey) {
            $this->geminiKey = env('GEMINI_API_KEY');
        }
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

    /*
    -------------------------------------------------------------------------
    NEW FUNCTION: Compress and Resize Image before sending to AI
    -------------------------------------------------------------------------
    */
    private function compressAndEncodeImage($fullPath)
    {
        $mime = mime_content_type($fullPath);
        $image = null;

        if ($mime == 'image/jpeg' || $mime == 'image/jpg') {
            $image = @imagecreatefromjpeg($fullPath);
        } elseif ($mime == 'image/png') {
            $image = @imagecreatefrompng($fullPath);
        } elseif ($mime == 'image/webp') {
            $image = @imagecreatefromwebp($fullPath);
        }

        if (!$image) {
            return base64_encode(file_get_contents($fullPath));
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $maxSize = 1024;

        if ($width > $maxSize || $height > $maxSize) {
            if ($width > $height) {
                $newWidth = $maxSize;
                $newHeight = intval($height * ($maxSize / $width));
            } else {
                $newHeight = $maxSize;
                $newWidth = intval($width * ($maxSize / $height));
            }

            $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

            if ($mime == 'image/png') {
                imagealphablending($resizedImage, false);
                imagesavealpha($resizedImage, true);
                $transparent = imagecolorallocatealpha($resizedImage, 255, 255, 255, 127);
                imagefilledrectangle($resizedImage, 0, 0, $newWidth, $newHeight, $transparent);
            }

            imagecopyresampled($resizedImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            $image = $resizedImage;
        }

        ob_start();
        imagejpeg($image, null, 80);
        $compressedImageData = ob_get_clean();

        imagedestroy($image);

        return base64_encode($compressedImageData);
    }

    public function generateReport(Request $request)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(300);

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
            Prepare Gemini Classification (Updated for Repairs, Replacements & Cleaning)
            --------------------------------
            */

            $parts = [];
            $parts[] = [
                "text" => "
                You are analyzing property inspection photos. This includes Cleaning, Repairs, and Part Replacements.

                For each image return JSON:
                [
                  {\"image_index\": 0, \"category\": \"窓の鍵交換\", \"stage\": \"before\", \"quality_score\": 8}
                ]

                Rules:
                - stage must be strictly 'before' or 'after'.
                - category: Identify the SPECIFIC task or item shown. Examples: '窓の鍵交換' (Window lock replacement), '排水口カバー交換' (Drain cover replacement), 'テープ補修' (Tape repair), 'キッチン清掃' (Kitchen cleaning). MUST be in Japanese.
                - quality_score (0-10) indicates image clarity and usefulness.
                - CRITICAL: Group related BEFORE and AFTER images into the EXACT SAME 'category' name so they match perfectly.
                - CRITICAL: Give a quality_score of 0 if the image is highly blurred, dark, irrelevant, or a duplicate.
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

                    // 2. Use the new compression function
                    $base64 = $this->compressAndEncodeImage($fullPath);
                    $mimeType = 'image/jpeg'; // Since our function converts everything to JPEG

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
            Select Best Before/After Images by Category (Not just Room)
            --------------------------------
            */

            $report = [];

            foreach ($analysis as $item) {
                $index = $item['image_index'];
                $category = trim($item['category']);
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

            /*
            --------------------------------
            Remove incomplete categories
            --------------------------------
            */

            foreach ($report as $category => $data) {
                if (!isset($data['before']) || !isset($data['after'])) {
                    unset($report[$category]);
                }
            }

            /*
            --------------------------------
            Generate AI Description (For Cleaning, Repair, and Replacement)
            --------------------------------
            */

            foreach ($report as $category => $data) {
                $description = $this->generateInspectionDescription(
                    $data['before']['file_path'],
                    $data['after']['file_path']
                );

                $report[$category]['description'] = $description;
            }

            /*
            Remove score and file_path before returning to frontend
            */

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
            return response()->json([
                "error" => "Internal server error",
                "message" => $e->getMessage(),
                "line" => $e->getLine()
            ], 500);
        }
    }

    private function generateInspectionDescription($beforePath, $afterPath)
    {
        // Server Limits Bypass
        ini_set('memory_limit', '512M');

        try {
            if (!Storage::disk('public')->exists($beforePath) || !Storage::disk('public')->exists($afterPath)) {
                return "作業による改善が確認されました。"; // Changed fallback message
            }

            $fullBeforePath = storage_path('app/public/' . $beforePath);
            $fullAfterPath = storage_path('app/public/' . $afterPath);

            // 3. Use the new compression function for description generation too
            $beforeBase64 = $this->compressAndEncodeImage($fullBeforePath);
            $beforeMime = 'image/jpeg';

            $afterBase64 = $this->compressAndEncodeImage($fullAfterPath);
            $afterMime = 'image/jpeg';

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            [
                                "text" => "
                                Compare these two property maintenance images.
                                Image 1 = BEFORE (Cleaning needed, broken part, or missing item)
                                Image 2 = AFTER (Cleaned, repaired, or replaced)
                                Describe the improvement, repair, or replacement that was done in one short, professional sentence.
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
                return "作業が正常に完了しました。";
            }

            return trim($response['candidates'][0]['content']['parts'][0]['text'] ?? "作業が完了しました。");
        } catch (\Throwable $e) {
            return "作業が完了しました。(エラー: " . $e->getMessage() . ")";
        }
    }
}
