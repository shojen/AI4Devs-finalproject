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

    'lines' => [
        'payment_on_account' => 'Payment on account of order :number',
    ],
];
