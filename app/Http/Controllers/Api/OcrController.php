<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LlmService;
use App\Services\OcrService;
use Illuminate\Http\Request;

class OcrController extends Controller
{
    protected $ocrService;

    public function __construct(OcrService $ocrService)
    {
        $this->ocrService = $ocrService;
    }

    public function process(Request $request, LlmService $llm)
    {
        set_time_limit(120);

        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png'],
        ]);

        $file = $request->file('document');

        $storedPath = $file->store('ocr-documents', 'public');

        $base64 = base64_encode(file_get_contents($file->getRealPath()));
        $mime   = $file->getMimeType();

        $ocrResult = $llm->extractTextFromImage($base64, $mime);

        return response()->json([
            'success' => true,
            'file' => [
                'original_name' => $file->getClientOriginalName(),
                'stored_path'   => $storedPath,
                'preview_url'   => asset('storage/' . $storedPath),
                'type'          => $mime,
            ],
            'ocr' => $ocrResult,
        ]);
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'id'        => 'nullable|exists:ocr_results,id',
            'file_path' => 'required|string',
            'ocr_data'  => 'required|array',
        ]);

        $ocr = $this->ocrService->save($validated);

        return response()->json([
            'success' => true,
            'message' => 'OCR saved successfully',
            'data'    => $ocr,
        ]);
    }

    public function history(Request $request)
    {
        $history = $this->ocrService->history(
            $request->get('per_page', 10)
        );

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    public function show(int $id)
    {
        $ocr = $this->ocrService->findById($id);

        return response()->json([
            'success' => true,
            'data' => $ocr,
        ]);
    }
}
