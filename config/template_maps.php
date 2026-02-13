<?php

return [
    'quotation' => [
        // Expected LLM output structure
        'expected_fields' => [
            'quotation_number' => null,
            'issue_date'       => null,
            'sender_name'       => null,
            'line_items' => [
                'item_name',
                'quantity',
                'unit',
                'unit_price',
                'amount',
            ],
        ],
        // Excel cell mapping
        'excel_mapping' => [
            'quotation_number' => 'F3',
            'issue_date'       => 'AH3',
            'sender_name'       => 'A5',

            'line_items' => [
                'start_row' => 19,
                'columns' => [
                    'item_name'  => 'A',
                    'quantity'   => 'U',
                    'unit'       => 'Z',
                    'unit_price' => 'AD',
                    'amount'     => 'AJ',
                ],
            ],
        ],
    ],
];
