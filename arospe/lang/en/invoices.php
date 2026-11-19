<?php

// Story 0101b: refusals of issuing an invoice and the text stored on a concept invoice line.
return [
    'errors' => [
        'issuer_incomplete' => 'Fill in the legal name, tax ID and address line 1 in the invoice settings before issuing invoices.',
        'nothing_left' => 'There is nothing left to invoice for this order.',
        'cancelled' => 'A cancelled order cannot be invoiced.',
        'out_of_date' => 'The invoices of this order are out of date after an edit. Regenerate them first.',
        'amount_format' => 'Enter the amount with a dot and at most two decimals, for example 200.50.',
        'amount_minimum' => 'The amount must be at least 0.01.',
        'amount_exceeds_remaining' => 'The amount is more than what is left to invoice: :remaining remains.',
        'customer_missing' => 'The customer of this order no longer exists, so it cannot be invoiced.',
        'number_collision' => 'An invoice with this number already exists. Change the invoice prefix in the invoice settings.',
    ],

    'pdf' => [
        'title' => 'Invoice',
        'corrective_title' => 'Corrective invoice',
        'number' => 'Number',
        'issued_on' => 'Issue date',
        'order' => 'Order',
        'issuer' => 'Issuer',
        'recipient' => 'Bill to',
        'tax_id' => 'Tax ID',
        'description' => 'Description',
        'sku' => 'SKU',
        'quantity' => 'Qty',
        'unit_price' => 'Unit price',
        'line_total' => 'Amount',
        'subtotal' => 'Taxable base',
        'shipping' => 'Shipping (untaxed)',
        'tax' => 'Tax',
        'total' => 'Total due',
        'corrects' => 'Corrects invoice',
        'reason' => 'Reason',
        'copy' => 'COPY',
        'sample' => 'SAMPLE',
        'reasons' => [
            'refund' => 'Refund',
            'order_edited' => 'Order edited',
            'order_cancelled' => 'Order cancelled',
        ],
    ],

    'lines' => [
        'payment_on_account' => 'Payment on account of order :number',
    ],
];
