<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class OcrExcelExport implements FromCollection, WithHeadings
{
    protected array $doc;
    protected string $lang;

    public function __construct(array $doc, string $lang = 'en')
    {
        $this->doc  = $doc;
        $this->lang = $lang;
    }

    /**
     * Excel rows (Document sheet)
     */
    public function collection(): Collection
    {
        $fields = [];
        $this->walk($this->doc, $fields);
        return collect($fields);
    }

    /**
     * Excel header row
     */
    public function headings(): array
    {
        return ['Section', 'Field', 'Value'];
    }

    /* ============================================================
       NORMALIZE (same as frontend normalizeDocument -> fields)
       ============================================================ */
    private function walk($node, array &$fields, string $section = ''): void
    {
        if (!is_array($node)) {
            return;
        }

        // SECTION (value = associative array)
        if (
            isset($node['label'], $node['value']) &&
            is_array($node['value']) &&
            !array_is_list($node['value'])
        ) {
            $nextSection = $node['label'][$this->lang] ?? $section;
            $this->walk($node['value'], $fields, $nextSection);
            return;
        }

        // TABLE (value = indexed array)
        if (
            isset($node['label'], $node['value']) &&
            is_array($node['value']) &&
            array_is_list($node['value'])
        ) {
            // Tables ignored in single-sheet export
            return;
        }

        // FIELD
        if (isset($node['label']) && array_key_exists('value', $node)) {
            $value = $node['value'];

            // LEAF FIELD (string / number / null)
            if ($value === null || !is_array($value)) {
                $fields[] = [
                    'Section' => $section,
                    'Field'   => $node['label'][$this->lang] ?? '',
                    'Value'   => $value ?? '',
                ];
                return;
            }

            // GROUP FIELD (object)
            foreach ($value as $v) {
                $this->walk($v, $fields, $section);
            }
            return;
        }

        // FALLBACK
        foreach ($node as $v) {
            $this->walk($v, $fields, $section);
        }
    }
}
