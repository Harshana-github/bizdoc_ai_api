<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TemplateService;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    protected $templateService;

    public function __construct(TemplateService $templateService)
    {
        $this->templateService = $templateService;
    }

    /**
     * Create Template
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'document_type_id' => 'required|exists:document_types,id',
            'file'             => 'required|file|mimes:xlsx,xls|max:5120',
        ]);

        $template = $this->templateService->createOrReplace(
            $validated['document_type_id'],
            $request->file('file')
        );

        return response()->json([
            'success' => true,
            'message' => 'Template saved successfully',
            'data'    => $template,
        ]);
    }
}
