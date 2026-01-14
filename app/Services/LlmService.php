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
                                "text" => "
                                Extract all readable information from this document.

                                Return STRICT JSON ONLY.
                                No markdown. No explanation.

                                Mandatory field:
                                - document_type

                                Rules for document_type:
                                - Always include a top-level field named \"document_type\"
                                - document_type must follow the same structure (label + value)
                                - Choose the value ONLY if clearly indicated by the document title or content
                                - If the document type is unclear, use value \"Other\"

                                Rules:
                                - Each field must be an object with:
                                - label: object containing translations
                                    - en: English label
                                    - ja: Japanese label
                                - value: extracted value
                                - Use snake_case keys
                                - Preserve document structure
                                - Arrays must contain objects using the same format
                                - Do NOT invent values
                                - If a value is missing, use null

                                Example format:
                                {
                                \"invoice_number\": {
                                    \"label\": {
                                    \"en\": \"Invoice Number\",
                                    \"ja\": \"請求書番号\"
                                    },
                                    \"value\": \"INV-001\"
                                }
                                }

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
