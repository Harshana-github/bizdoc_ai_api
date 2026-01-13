<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LlmService
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $model;

    public function __construct()
    {
        $this->apiKey  = config('services.llm.key');
        $this->baseUrl = rtrim(config('services.llm.base_url'), '/');
        $this->model   = config('services.llm.model');
    }

    public function extractTextFromImage(string $base64Image, string $mime): array
    {
        try {
            $endpoint = "{$this->baseUrl}/chat/completions";

            $payload = [
                "model" => $this->model,
                "request_id" => uniqid('ocr_', true),
                "messages" => [
                    [
                        "role" => "user",
                        "content" => [
                            [
                                "type" => "text",
                                "text" => "Extract all readable text from this document. 
                                Return JSON only.

                                Request ID: " . uniqid('', true)
                            ],
                            [
                                "type" => "image_url",
                                "image_url" => [
                                    "url" => "data:{$mime};base64,{$base64Image}"
                                ]
                            ]
                        ]
                    ]
                ]
            ];

            $response = Http::timeout(120)
                ->withToken($this->apiKey)
                ->withHeaders([
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                    'Pragma'        => 'no-cache',
                ])
                ->post($endpoint, $payload);


            if (! $response->successful()) {
                return [
                    "error" => true,
                    "status" => $response->status(),
                    "body"   => $response->body(),
                ];
            }

            return [
                "error" => false,
                "data"  => $response->json(),
            ];
        } catch (\Throwable $e) {
            return [
                "error" => true,
                "exception" => true,
                "message" => $e->getMessage(),
            ];
        }
    }
}
