<?php

// Story 0102 FD-13: paper, margins and limits of the invoice PDF render.
return [
    'pdf' => [
        'format' => env('INVOICE_PDF_FORMAT', 'a4'),
        'margin_mm' => (int) env('INVOICE_PDF_MARGIN_MM', 15),
        'timeout_seconds' => (int) env('INVOICE_PDF_TIMEOUT_SECONDS', 30),
        'logo_max_bytes' => (int) env('INVOICE_PDF_LOGO_MAX_BYTES', 1_048_576),
    ],

    // Story 0104: the queued invoice e-mail. `max_attachments` caps the PDFs of one mail.
    'delivery' => [
        'tries' => (int) env('INVOICE_DELIVERY_TRIES', 3),
        'backoff_seconds' => (int) env('INVOICE_DELIVERY_BACKOFF_SECONDS', 60),
        // At least max_attachments PDF renders plus a margin; override with care.
        'timeout_seconds' => (int) env('INVOICE_DELIVERY_TIMEOUT_SECONDS', 10 * (int) env('INVOICE_PDF_TIMEOUT_SECONDS', 30) + 60),
        'max_attachments' => 10,
    ],
];
