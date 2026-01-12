<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OcrController extends Controller
{
    public function process(Request $request)
    {
        $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $file = $request->file('document');

        $storedPath = $file->store('ocr-documents', 'public');

        return response()->json([
            'success' => true,
            'file' => [
                'original_name' => $file->getClientOriginalName(),
                'stored_path'   => $storedPath,
                'preview_url'   => asset('storage/' . $storedPath),
                'size'          => $file->getSize(),
                'type'          => $file->getMimeType(),
            ],
        ]);
    }
}
