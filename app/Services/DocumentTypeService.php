<?php

namespace App\Services;

use App\Models\DocumentType;
use Illuminate\Database\Eloquent\Collection;

class DocumentTypeService
{
    protected $documentType;

    public function __construct(DocumentType $documentType)
    {
        $this->documentType = $documentType;
    }

    /**
     * Retrieve all document types
     */
    public function all(): Collection
    {
        return $this->documentType
            ->orderBy('id')
            ->get();
    }
}
