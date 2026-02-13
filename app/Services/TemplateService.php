<?php

namespace App\Services;

use App\Models\Template;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TemplateService
{
    protected $template;

    public function __construct(Template $template)
    {
        $this->template = $template;
    }

    /**
     * Create OR Replace Template for Document Type
     */
    public function createOrReplace(int $documentTypeId, $file): Template
    {
        // 1️⃣ Check existing template for this document type
        $existing = $this->template
            ->where('document_type_id', $documentTypeId)
            ->first();

        if ($existing) {
            // delete old file
            if (
                $existing->file_name &&
                Storage::disk('public')->exists($existing->file_name)
            ) {
                Storage::disk('public')->delete($existing->file_name);
            }

            // delete old database record
            $existing->delete();
        }

        // 2️⃣ Store new file
        $storedPath = $this->storeFile($file);

        // 3️⃣ Create new record
        return $this->template->create([
            'document_type_id' => $documentTypeId,
            'file_name'        => $storedPath,
        ]);
    }

    /**
     * Store file with unique name
     */
    protected function storeFile($file): string
    {
        $extension = $file->getClientOriginalExtension();

        $uniqueName = 'template_' . Str::uuid() . '.' . $extension;

        return $file->storeAs(
            'template',
            $uniqueName,
            'public'
        );
    }
}
