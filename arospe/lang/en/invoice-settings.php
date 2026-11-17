<?php

// Story 0100: attribute names and refusal messages of the invoice settings (FD-13).
return [
    'attributes' => [
        'issuer_legal_name' => 'legal name',
        'issuer_tax_id' => 'tax ID',
        'issuer_address_line1' => 'address line 1',
        'issuer_address_line2' => 'address line 2',
        'issuer_city' => 'city',
        'issuer_postal_code' => 'postal code',
        'issuer_province' => 'province',
        'issuer_country' => 'country',
        'logo_media_id' => 'logo',
        'primary_color' => 'primary colour',
        'accent_color' => 'accent colour',
        'template' => 'template',
        'standard_prefix' => 'standard invoice prefix',
        'corrective_prefix' => 'corrective invoice prefix',
        'standard_year_format' => 'standard invoice year format',
        'corrective_year_format' => 'corrective invoice year format',
        'standard_separator' => 'standard invoice separator',
        'corrective_separator' => 'corrective invoice separator',
        'standard_padding' => 'standard invoice number length',
        'corrective_padding' => 'corrective invoice number length',
        'reset_numbering_yearly' => 'yearly numbering reset',
        'auto_issue_on_order' => 'automatic invoicing',
    ],

    'validation' => [
        'year_required_when_reset' => 'The year must be part of the number when numbering restarts every year.',
        'prefixes_must_differ' => 'The corrective invoice prefix must differ from the standard invoice prefix.',
        'auto_issue_requires_issuer' => 'Automatic invoicing needs the legal name, tax ID and address line 1 to be filled in.',
    ],
];
