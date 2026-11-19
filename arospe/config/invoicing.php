<?php

// Story 0102 FD-13: paper, margins and limits of the invoice PDF render.
return [
    'pdf' => [
        'format' => env('INVOICE_PDF_FORMAT', 'a4'),
        'margin_mm' => (int) env('INVOICE_PDF_MARGIN_MM', 15),
        'timeout_seconds' => (int) env('INVOICE_PDF_TIMEOUT_SECONDS', 30),
        'logo_max_bytes' => (int) env('INVOICE_PDF_LOGO_MAX_BYTES', 1_048_576),
    ],
];
