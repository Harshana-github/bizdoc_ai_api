<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DocumentTypeService;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    protected $documentTypeService;

    public function __construct(DocumentTypeService $documentTypeService)
    {
        $this->documentTypeService = $documentTypeService;
    }

    /**
     * Get all document types
     */
    public function index()
    {
        $types = $this->documentTypeService->all();

        return response()->json([
            'success' => true,
            'data'    => $types,
        ]);
    }
}
