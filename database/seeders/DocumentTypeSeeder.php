<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\DocumentType;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'key' => 'quotation',
                'name_en' => 'Quotation',
                'name_ja' => '見積書',
            ],
            [
                'key' => 'invoice',
                'name_en' => 'Invoice',
                'name_ja' => '請求書',
            ],
            [
                'key' => 'receipt',
                'name_en' => 'Receipt',
                'name_ja' => '領収書',
            ],
            [
                'key' => 'purchase_order',
                'name_en' => 'Purchase Order',
                'name_ja' => '発注書',
            ],
            [
                'key' => 'delivery_note',
                'name_en' => 'Delivery Note',
                'name_ja' => '納品書',
            ],
            [
                'key' => 'contract',
                'name_en' => 'Contract',
                'name_ja' => '契約書',
            ],
            [
                'key' => 'greeting_card',
                'name_en' => 'Greeting Card',
                'name_ja' => '挨拶状',
            ],
            [
                'key' => 'other',
                'name_en' => 'Other',
                'name_ja' => 'その他',
            ],
        ];

        foreach ($types as $type) {
            DocumentType::updateOrCreate(
                ['key' => $type['key']],
                $type
            );
        }
    }
}
