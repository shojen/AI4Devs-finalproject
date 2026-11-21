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
        'customer_deleted' => 'The customer of this invoice has been deleted, so it cannot be sent. It can still be downloaded.',
        'customer_email_unusable' => 'The customer e-mail address is not valid. Correct it before sending the invoice.',
        'nothing_to_send' => 'There is no invoice to send for this order.',
        'too_many_attachments' => 'A single e-mail can carry at most :max invoices. Send them one by one.',
        'issued_but_not_queued' => 'The invoice was issued but the e-mail could not be queued. Send the invoice manually.',
    ],

    'mail' => [
        'subject' => 'Your invoice :numbers|Your invoices :numbers',
        'greeting' => 'Hello,',
        'body' => 'Please find your invoice :numbers attached.|Please find your invoices :numbers attached.',
        'copy_notice' => 'The attached copies are marked COPY.',
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

    // Story 0105: copy of the notification sent when the automatic invoicing of a new order fails.
    'automatic' => [
        'title' => 'Automatic invoice failed',
        'stages' => [
            'issue' => 'The invoice could not be issued.',
            'send' => 'The invoice was issued but could not be sent.',
        ],
        'reasons' => [
            'issuer_incomplete' => 'The issuer data in the invoice settings is incomplete.',
            'number_collision' => 'The invoice number was already taken.',
            'lock_timeout' => 'The database was busy; try again.',
            'customer_deleted' => 'The customer was deleted, so the invoice was not sent.',
            'send_failed' => 'The invoice could not be queued for sending.',
            'unexpected' => 'An unexpected error occurred; see the application log.',
        ],
    ],
];
