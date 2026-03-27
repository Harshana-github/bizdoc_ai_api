<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Job;
use App\Models\JobImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ImageCategorizerController extends Controller
{
    private $geminiKey;
    private $endpoint;

    public function __construct()
    {
        $this->geminiKey = env('GEMINI_API_KEY');
        $this->endpoint = "https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key={$this->geminiKey}";
    }

    public function upload(Request $request)
    {
        set_time_limit(120);

        $request->validate([
            'job_code' => ['required', 'string'],
            'images' => ['required', 'array'],
            'images.*' => ['file', 'mimes:jpg,jpeg,png']
        ]);

        $job = Job::firstOrCreate([
            'code' => $request->job_code
        ]);

        $savedImages = [];

        foreach ($request->file('images') as $image) {

            $path = $image->store('job-images', 'public');

            $savedImages[] = JobImage::create([
                'job_id' => $job->id,
                'image_path' => $path
            ]);
        }

        return response()->json([
            'success' => true,
            'job_id' => $job->id,
            'images' => $savedImages
        ]);
    }

    public function deleteImages(Request $request)
    {
        $request->validate([
            'image_ids' => ['required', 'array'],
            'image_ids.*' => ['integer']
        ]);

        $images = JobImage::whereIn('id', $request->image_ids)->get();

        foreach ($images as $image) {

            if (Storage::disk('public')->exists($image->image_path)) {
                Storage::disk('public')->delete($image->image_path);
            }

            $image->delete();
        }

        return response()->json([
            'success' => true
        ]);
    }

    public function getImages($jobCode)
    {
        $job = Job::where('code', $jobCode)
            ->with('images')
            ->first();

        return response()->json([
            'job_code' => $jobCode,
            'job_id' => $job?->id,
            'images' => $job?->images ?? []
        ]);
    }

    // public function generateReport(Request $request)
    // {
    //     set_time_limit(120);
    //     $images = $request->input('images');

    //     if (!$images || !is_array($images)) {
    //         return response()->json([
    //             "message" => "Images are required"
    //         ], 422);
    //     }

    //     try {

    //         /*
    //         --------------------------------
    //         Prepare Gemini Classification
    //         --------------------------------
    //         */

    //         $parts = [];

    //         $parts[] = [
    //             "text" => "
    //             You are analyzing cleaning inspection photos.

    //             For each image return JSON:

    //             [
    //             {image_index, room_type, stage(before|after), quality_score(0-10)}
    //             ]

    //             Rules:
    //             - stage must be either before or after
    //             - quality_score indicates image clarity
    //             - image_index starts from 0

    //             Return JSON only.
    //             "
    //         ];

    //         $imageMap = [];

    //         foreach ($images as $index => $img) {

    //             $imageMap[$index] = $img;

    //             // $imageResponse = Http::timeout(30)->get($img['url']);

    //             // if (!$imageResponse->successful()) {
    //             //     continue;
    //             // }

    //             // $base64 = base64_encode($imageResponse->body());

    //             $path = $img['image_path'];

    //             if (!Storage::disk('public')->exists($path)) {
    //                 continue;
    //             }

    //             $file = Storage::disk('public')->get($path);

    //             $base64 = base64_encode($file);

    //             $parts[] = [
    //                 "inline_data" => [
    //                     "mime_type" => "image/jpeg",
    //                     "data" => $base64
    //                 ]
    //             ];
    //         }

    //         $payload = [
    //             "contents" => [
    //                 [
    //                     "parts" => $parts
    //                 ]
    //             ]
    //         ];

    //         $response = Http::timeout(120)
    //             ->withHeaders([
    //                 "Content-Type" => "application/json"
    //             ])
    //             ->post($this->endpoint, $payload);

    //         if (!$response->successful()) {
    //             return response()->json([
    //                 "error" => "Gemini request failed",
    //                 "details" => $response->body()
    //             ], 500);
    //         }

    //         /*
    //         --------------------------------
    //         Parse Gemini Response
    //         --------------------------------
    //         */

    //         $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

    //         $text = trim($text);
    //         $text = preg_replace('/^```json/', '', $text);
    //         $text = preg_replace('/```$/', '', $text);

    //         $analysis = json_decode($text, true);

    //         if (!$analysis) {
    //             return response()->json([
    //                 "error" => "Failed to parse Gemini response",
    //                 "raw" => $text
    //             ], 500);
    //         }

    //         /*
    //         --------------------------------
    //         Select Best Before/After Images
    //         --------------------------------
    //         */

    //         $report = [];

    //         foreach ($analysis as $item) {

    //             $index = $item['image_index'];
    //             $room = strtolower($item['room_type']);
    //             $stage = strtolower($item['stage']);
    //             $score = $item['quality_score'];

    //             if (!isset($imageMap[$index])) {
    //                 continue;
    //             }

    //             $image = $imageMap[$index];

    //             if (
    //                 !isset($report[$room][$stage]) ||
    //                 $report[$room][$stage]['score'] < $score
    //             ) {

    //                 $report[$room][$stage] = [
    //                     "id" => $image['id'],
    //                     "url" => $image['url'],
    //                     "score" => $score
    //                 ];
    //             }
    //         }

    //         /*
    //         --------------------------------
    //         Remove incomplete rooms
    //         --------------------------------
    //         */

    //         foreach ($report as $room => $data) {
    //             if (!isset($data['before']) || !isset($data['after'])) {
    //                 unset($report[$room]);
    //             }
    //         }

    //         /*
    //         --------------------------------
    //         Generate AI Cleaning Description
    //         --------------------------------
    //         */

    //         foreach ($report as $room => $data) {

    //             $description = $this->generateCleaningDescription(
    //                 $data['before']['url'],
    //                 $data['after']['url']
    //             );

    //             $report[$room]['description'] = $description;
    //         }

    //         /*
    //         Remove score before returning
    //         */

    //         foreach ($report as $room => $data) {

    //             unset($report[$room]['before']['score']);
    //             unset($report[$room]['after']['score']);
    //         }

    //         return response()->json([
    //             "report" => $report
    //         ]);
    //     } catch (\Throwable $e) {

    //         return response()->json([
    //             "error" => "Internal server error",
    //             "message" => $e->getMessage()
    //         ], 500);
    //     }
    // }

    // public function generateReport(Request $request)
    // {
    //     // Log::debug($request);
    //     set_time_limit(120);

    //     $images = $request->input('images');

    //     if (!$images || !is_array($images)) {
    //         return response()->json([
    //             "message" => "Images are required"
    //         ], 422);
    //     }

    //     try {

    //         /*
    //     --------------------------------
    //     Prepare Gemini Classification
    //     --------------------------------
    //     */

    //         $parts = [];

    //         $parts[] = [
    //             "text" => "
    //         You are analyzing cleaning inspection photos.

    //         For each image return JSON:

    //         [
    //         {image_index, room_type, stage(before|after), quality_score(0-10)}
    //         ]

    //         Rules:
    //         - stage must be either before or after
    //         - quality_score indicates image clarity
    //         - image_index starts from 0

    //         Return JSON only.
    //         "
    //         ];

    //         $imageMap = [];

    //         foreach ($images as $index => $img) {

    //             $imageMap[$index] = $img;

    //             $path = $img['image_path'];

    //             if (!Storage::disk('public')->exists($path)) {
    //                 continue;
    //             }

    //             $file = Storage::disk('public')->get($path);

    //             $base64 = base64_encode($file);

    //             $parts[] = [
    //                 "inline_data" => [
    //                     "mime_type" => "image/jpeg",
    //                     "data" => $base64
    //                 ]
    //             ];
    //         }

    //         $payload = [
    //             "contents" => [
    //                 [
    //                     "parts" => $parts
    //                 ]
    //             ]
    //         ];

    //         $response = Http::timeout(120)
    //             ->withHeaders([
    //                 "Content-Type" => "application/json"
    //             ])
    //             ->post($this->endpoint, $payload);

    //         if (!$response->successful()) {
    //             return response()->json([
    //                 "error" => "Gemini request failed",
    //                 "details" => $response->body()
    //             ], 500);
    //         }

    //         /*
    //     --------------------------------
    //     Parse Gemini Response
    //     --------------------------------
    //     */

    //         $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? '';

    //         $text = trim($text);
    //         $text = preg_replace('/^```json/', '', $text);
    //         $text = preg_replace('/```$/', '', $text);

    //         $analysis = json_decode($text, true);

    //         if (!$analysis) {
    //             return response()->json([
    //                 "error" => "Failed to parse Gemini response",
    //                 "raw" => $text
    //             ], 500);
    //         }

    //         /*
    //     --------------------------------
    //     Select Best Before/After Images
    //     --------------------------------
    //     */

    //         $report = [];

    //         foreach ($analysis as $item) {

    //             $index = $item['image_index'];
    //             $room = strtolower($item['room_type']);
    //             $stage = strtolower($item['stage']);
    //             $score = $item['quality_score'];

    //             if (!isset($imageMap[$index])) {
    //                 continue;
    //             }

    //             $image = $imageMap[$index];

    //             if (
    //                 !isset($report[$room][$stage]) ||
    //                 $report[$room][$stage]['score'] < $score
    //             ) {

    //                 $report[$room][$stage] = [
    //                     "id" => $image['id'],
    //                     "image_path" => $image['image_path'],
    //                     "score" => $score
    //                 ];
    //             }
    //         }

    //         /*
    //     --------------------------------
    //     Remove incomplete rooms
    //     --------------------------------
    //     */

    //         foreach ($report as $room => $data) {
    //             if (!isset($data['before']) || !isset($data['after'])) {
    //                 unset($report[$room]);
    //             }
    //         }

    //         /*
    //     --------------------------------
    //     Generate AI Cleaning Description
    //     --------------------------------
    //     */

    //         foreach ($report as $room => $data) {

    //             $description = $this->generateCleaningDescription(
    //                 $data['before']['image_path'],
    //                 $data['after']['image_path']
    //             );

    //             $report[$room]['description'] = $description;
    //         }

    //         /*
    //     Remove score before returning
    //     */

    //         foreach ($report as $room => $data) {

    //             unset($report[$room]['before']['score']);
    //             unset($report[$room]['after']['score']);
    //         }

    //         return response()->json([
    //             "report" => $report
    //         ]);
    //     } catch (\Throwable $e) {

    //         return response()->json([
    //             "error" => "Internal server error",
    //             "message" => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function generateReport(Request $request)
    {
        set_time_limit(120);

        $images = $request->input('images');

        if (!$images || !is_array($images)) {
            return response()->json([
                "message" => "Images are required"
            ], 422);
        }

        try {

            /*
            --------------------------------
            Prepare Gemini Classification
            --------------------------------
            */

            $parts = [];

            $parts[] = [
                "text" => "
                You are analyzing cleaning inspection photos.

                For each image return JSON:

                [
                {image_index, room_type, stage(before|after), quality_score(0-10)}
                ]

                Rules:
                - stage must be either before or after
                - quality_score indicates image clarity
                - image_index starts from 0

                Return JSON only.
                "
            ];

            $imageMap = [];

            foreach ($images as $index => $img) {

                $imageMap[$index] = $img;

                $url = $img['url'] ?? null;

                if (!$url) {
                    continue;
                }

                try {
                    $imageResponse = Http::timeout(30)->get($url);

                    if (!$imageResponse->successful()) {
                        continue;
                    }

                    $file = $imageResponse->body();
                    $base64 = base64_encode($file);

                    $parts[] = [
                        "inline_data" => [
                            "mime_type" => "image/jpeg",
                            "data" => $base64
                        ]
                    ];
                } catch (\Exception $e) {
                    continue;
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

            /*
            --------------------------------
            Parse Gemini Response
            --------------------------------
            */

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
                $room = strtolower($item['room_type']);
                $stage = strtolower($item['stage']);
                $score = $item['quality_score'];

                if (!isset($imageMap[$index])) {
                    continue;
                }

                $image = $imageMap[$index];

                if (
                    !isset($report[$room][$stage]) ||
                    $report[$room][$stage]['score'] < $score
                ) {

                    $report[$room][$stage] = [
                        "id" => $image['id'],
                        "url" => $image['url'],
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
            Generate AI Cleaning Description
            --------------------------------
            */

            foreach ($report as $room => $data) {

                $description = $this->generateCleaningDescription(
                    $data['before']['url'], // Fix 4: 'image_path' වෙනුවට 'url'
                    $data['after']['url']   // Fix 4: 'image_path' වෙනුවට 'url'
                );

                $report[$room]['description'] = $description;
            }

            /*
            Remove score before returning
            */

            foreach ($report as $room => $data) {
                unset($report[$room]['before']['score']);
                unset($report[$room]['after']['score']);
            }

            return response()->json([
                "report" => $report
            ]);
        } catch (\Throwable $e) {

            return response()->json([
                "error" => "Internal server error",
                "message" => $e->getMessage(),
                "line" => $e->getLine() // Debugging ලේසි වෙන්න line number එක add කළා
            ], 500);
        }
    }

    // private function generateCleaningDescription($beforeUrl, $afterUrl)
    // {
    //     try {

    //         $beforeImage = Http::get($beforeUrl);
    //         $afterImage = Http::get($afterUrl);

    //         if (!$beforeImage->successful() || !$afterImage->successful()) {
    //             return "Cleaning improvement detected.";
    //         }

    //         $beforeBase64 = base64_encode($beforeImage->body());
    //         $afterBase64 = base64_encode($afterImage->body());

    //         $payload = [
    //             "contents" => [
    //                 [
    //                     "parts" => [
    //                         [
    //                             "text" => "
    //                             Compare two cleaning images.

    //                             Image 1 = BEFORE cleaning
    //                             Image 2 = AFTER cleaning

    //                             Describe the cleaning improvement in one short sentence.
    //                             "
    //                         ],
    //                         [
    //                             "inline_data" => [
    //                                 "mime_type" => "image/jpeg",
    //                                 "data" => $beforeBase64
    //                             ]
    //                         ],
    //                         [
    //                             "inline_data" => [
    //                                 "mime_type" => "image/jpeg",
    //                                 "data" => $afterBase64
    //                             ]
    //                         ]
    //                     ]
    //                 ]
    //             ]
    //         ];

    //         $response = Http::timeout(60)
    //             ->withHeaders([
    //                 "Content-Type" => "application/json"
    //             ])
    //             ->post($this->endpoint, $payload);

    //         if (!$response->successful()) {
    //             return "Cleaning completed successfully.";
    //         }

    //         return trim($response['candidates'][0]['content']['parts'][0]['text'] ?? "Cleaning completed.");
    //     } catch (\Throwable $e) {

    //         return "Cleaning completed.";
    //     }
    // }
    // private function generateCleaningDescription($beforePath, $afterPath)
    // {
    //     try {

    //         if (
    //             !Storage::disk('public')->exists($beforePath) ||
    //             !Storage::disk('public')->exists($afterPath)
    //         ) {
    //             return "Cleaning improvement detected.";
    //         }

    //         $beforeFile = Storage::disk('public')->get($beforePath);
    //         $afterFile = Storage::disk('public')->get($afterPath);

    //         $beforeBase64 = base64_encode($beforeFile);
    //         $afterBase64 = base64_encode($afterFile);

    //         $payload = [
    //             "contents" => [
    //                 [
    //                     "parts" => [
    //                         [
    //                             "text" => "
    //                         Compare two cleaning images.

    //                         Image 1 = BEFORE cleaning
    //                         Image 2 = AFTER cleaning

    //                         Describe the cleaning improvement in one short sentence.
    //                         "
    //                         ],
    //                         [
    //                             "inline_data" => [
    //                                 "mime_type" => "image/jpeg",
    //                                 "data" => $beforeBase64
    //                             ]
    //                         ],
    //                         [
    //                             "inline_data" => [
    //                                 "mime_type" => "image/jpeg",
    //                                 "data" => $afterBase64
    //                             ]
    //                         ]
    //                     ]
    //                 ]
    //             ]
    //         ];

    //         $response = Http::timeout(60)
    //             ->withHeaders([
    //                 "Content-Type" => "application/json"
    //             ])
    //             ->post($this->endpoint, $payload);

    //         if (!$response->successful()) {
    //             return "Cleaning completed successfully.";
    //         }

    //         return trim(
    //             $response['candidates'][0]['content']['parts'][0]['text']
    //                 ?? "Cleaning completed."
    //         );
    //     } catch (\Throwable $e) {

    //         return "Cleaning completed.";
    //     }
    // }

    private function generateCleaningDescription($beforeUrl, $afterUrl)
    {
        try {
            $beforeImage = Http::timeout(30)->get($beforeUrl);
            $afterImage = Http::timeout(30)->get($afterUrl);

            if (!$beforeImage->successful() || !$afterImage->successful()) {
                return "Cleaning improvement detected.";
            }

            $beforeBase64 = base64_encode($beforeImage->body());
            $afterBase64 = base64_encode($afterImage->body());

            $payload = [
                "contents" => [
                    [
                        "parts" => [
                            [
                                "text" => "
                                Compare two cleaning images.

                                Image 1 = BEFORE cleaning
                                Image 2 = AFTER cleaning

                                Describe the cleaning improvement in one short sentence.
                                "
                            ],
                            [
                                "inline_data" => [
                                    "mime_type" => "image/jpeg",
                                    "data" => $beforeBase64
                                ]
                            ],
                            [
                                "inline_data" => [
                                    "mime_type" => "image/jpeg",
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
                return "Cleaning completed successfully.";
            }

            return trim($response['candidates'][0]['content']['parts'][0]['text'] ?? "Cleaning completed.");
        } catch (\Throwable $e) {
            return "Cleaning completed. (Error: " . $e->getMessage() . ")";
        }
    }
}
