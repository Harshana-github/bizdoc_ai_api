<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\Template;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;


class TemplateService
{
    protected $template;
    protected $document_type;

    public function __construct(Template $template, DocumentType $document_type)
    {
        $this->template = $template;
        $this->document_type = $document_type;
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

    public function exportFromTemplate(array $doc, string $lang)
    {
        /*
        |--------------------------------------------------------------------------
        | Validate Document Type
        |--------------------------------------------------------------------------
        */

        $rawType = trim($doc['document_type']['value'] ?? '');

        if (!$rawType) {
            return response()->json([
                'success' => false,
                'key' => 'document_type_missing',
            ], 400);
        }

        $normalized = mb_strtolower($rawType);

        $documentType = $this->document_type::all()->first(function ($type) use ($normalized) {
            return $normalized === mb_strtolower($type->name_en)
                || $normalized === mb_strtolower($type->name_ja)
                || $normalized === mb_strtolower($type->key);
        });

        if (!$documentType) {
            return response()->json([
                'success' => false,
                'key' => 'document_type_not_registered',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Get Template
        |--------------------------------------------------------------------------
        */

        $template = $this->template
            ->where('document_type_id', $documentType->id)
            ->first();

        if (!$template) {
            return response()->json([
                'success' => false,
                'key' => 'template_not_configured',
            ], 404);
        }

        $templatePath = storage_path('app/public/' . $template->file_name);

        if (!file_exists($templatePath)) {
            return response()->json([
                'success' => false,
                'key' => 'template_file_missing',
            ], 404);
        }

        /*
        |--------------------------------------------------------------------------
        | Load Template
        |--------------------------------------------------------------------------
        */

        $spreadsheet = IOFactory::load($templatePath);
        $sheet = $spreadsheet->getActiveSheet();

        /*
        |--------------------------------------------------------------------------
        | Insert Data Into Template
        |--------------------------------------------------------------------------
        */

        $quotation_number = $doc['quotation_number']['value'] ?? '';
        $issue_date = $doc['issue_date']['value'] ?? '';

        // If F3:J3 is merged, set value only to F3
        $sheet->setCellValue('F3', $quotation_number);
        // If AH3:AO3 is merged, set value only to F3
        $sheet->setCellValue('AH3', $issue_date);

        $lineItems = $doc['line_items'] ?? [];

        $startRow = 19;


        foreach ($lineItems as $index => $item) {

            $row = $startRow + $index;

            $itemName  = $item['item_name']['value'] ?? '';
            $quantity  = $item['quantity']['value'] ?? '';
            $unit      = $item['unit']['value'] ?? '';
            $unitPrice = $item['unit_price']['value'] ?? '';
            $amount    = $item['amount']['value'] ?? '';

            // A–T (merged)
            $sheet->setCellValue("A{$row}", $itemName);

            // U–Y (merged)
            $sheet->setCellValue("U{$row}", $quantity);

            // Z–AC (merged)
            $sheet->setCellValue("Z{$row}", $unit);

            // AD–AI (merged)
            $sheet->setCellValue("AD{$row}", $unitPrice);

            // AJ–AO (merged)
            $sheet->setCellValue("AJ{$row}", $amount);
        }

        /*
        |--------------------------------------------------------------------------
        | Prepare Correct Writer
        |--------------------------------------------------------------------------
        */

        $extension = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));

        $writerType = $extension === 'xls' ? 'Xls' : 'Xlsx';

        $contentType = $writerType === 'Xls'
            ? 'application/vnd.ms-excel'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        /*
        |--------------------------------------------------------------------------
        | Stream Download Modified File
        |--------------------------------------------------------------------------
        */

        return response()->streamDownload(function () use ($spreadsheet, $writerType) {
            $writer = IOFactory::createWriter($spreadsheet, $writerType);
            $writer->save('php://output');
        }, basename($template->file_name), [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . basename($template->file_name) . '"',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
