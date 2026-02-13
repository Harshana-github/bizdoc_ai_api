<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\Template;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Services\LlmService;


class TemplateService
{
    protected $template;
    protected $document_type;
    protected $llmService;

    public function __construct(Template $template, DocumentType $document_type, LlmService $llmService)
    {
        $this->template = $template;
        $this->document_type = $document_type;
        $this->llmService = $llmService;
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
        | Load Template Map From Config
        |--------------------------------------------------------------------------
        */

        $templateConfig = config("template_maps.{$documentType->key}");

        if (!$templateConfig) {
            return response()->json([
                'success' => false,
                'key' => 'template_not_configured',
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
        | Insert Data Based On Document Type
        |--------------------------------------------------------------------------
        */

        switch ($documentType->key) {

            case 'quotation':
                $this->fillQuotationTemplate($sheet, $doc, $templateConfig);
                break;

            default:
                return response()->json([
                    'success' => false,
                    'key' => 'template_not_configured',
                ], 404);
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

    private function fillQuotationTemplate(
        $sheet,
        array $doc,
        array $templateConfig
    ): void {

        set_time_limit(120);

        /*
        |--------------------------------------------------------------------------
        | Step 1: Normalize Using LLM
        |--------------------------------------------------------------------------
        */

        $normalizedDoc = $this->llmService->normalizeWithLlm(
            $doc,
            $templateConfig['expected_fields']
        );

        $normalizedDoc = $this->enforceNumericFields($normalizedDoc);

        /*
        |--------------------------------------------------------------------------
        | Step 2: Get Excel Mapping Config
        |--------------------------------------------------------------------------
        */

        $excelMap = $templateConfig['excel_mapping'];

        /*
        |--------------------------------------------------------------------------
        | Step 3: Map Simple Fields (quotation_number, issue_date, etc.)
        |--------------------------------------------------------------------------
        */

        foreach ($excelMap as $field => $mapping) {

            // Skip line_items (handled separately)
            if ($field === 'line_items') {
                continue;
            }

            $value = $normalizedDoc[$field] ?? '';

            $sheet->setCellValue($mapping, $value);
        }

        /*
        |--------------------------------------------------------------------------
        | Step 4: Map Line Items Dynamically
        |--------------------------------------------------------------------------
        */

        if (isset($excelMap['line_items'])) {

            $lineConfig = $excelMap['line_items'];

            $startRow = $lineConfig['start_row'] ?? 1;
            $columns  = $lineConfig['columns'] ?? [];

            $lineItems = $normalizedDoc['line_items'] ?? [];

            foreach ($lineItems as $index => $item) {

                $row = $startRow + $index;

                foreach ($columns as $fieldName => $columnLetter) {

                    $value = $item[$fieldName] ?? '';

                    $sheet->setCellValue("{$columnLetter}{$row}", $value);
                }
            }
        }
    }

    private function enforceNumericFields(array $data): array
    {
        if (!isset($data['line_items']) || !is_array($data['line_items'])) {
            return $data;
        }

        foreach ($data['line_items'] as &$item) {

            foreach (['quantity', 'unit_price', 'amount'] as $numericField) {

                if (isset($item[$numericField])) {

                    // Remove commas if any (e.g., "1,000")
                    $cleanValue = str_replace(',', '', $item[$numericField]);

                    // Cast to float
                    $item[$numericField] = is_numeric($cleanValue)
                        ? (float) $cleanValue
                        : 0;
                }
            }
        }

        return $data;
    }
}
