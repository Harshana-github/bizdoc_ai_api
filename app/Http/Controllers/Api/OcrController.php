<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\LlmService;
use Illuminate\Support\Facades\Storage;

class OcrController extends Controller
{
public function process(Request $request)
{
    $request->validate([
        'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
    ]);

    $file = $request->file('document');

    return response()->json([
        'success' => true,
        'file' => [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'type' => $file->getMimeType(),
        ],
    ]);
}

}
