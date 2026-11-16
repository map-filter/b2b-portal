<?php

return [
    'supplier' => [
        'name' => env('B2B_SUPPLIER_NAME', 'ТОО «ЭлектроОпт»'),
        'bin' => env('B2B_SUPPLIER_BIN', '000000000000'),
        'address' => env('B2B_SUPPLIER_ADDRESS', 'г. Павлодар, ул. Промышленная, 1'),
        'bank' => env('B2B_SUPPLIER_BANK', 'АО «Иртыш Банк»'),
        'iik' => env('B2B_SUPPLIER_IIK', 'KZ000000000000000000'),
        'bik' => env('B2B_SUPPLIER_BIK', 'IRTYKZKA'),
        'kbe' => env('B2B_SUPPLIER_KBE', '17'),
        'vat_percent' => (float) env('B2B_VAT_PERCENT', 16),
    ],
    'invoice_valid_days' => 5,
];
