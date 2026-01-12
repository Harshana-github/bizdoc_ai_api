<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LlmService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OcrController extends Controller
{
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
                'preview_url'   => asset('storage/' . $storedPath),
                'type'          => $mime,
            ],
            'ocr' => $ocrResult,
        ]);
    }
}
