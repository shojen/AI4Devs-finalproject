<?php

// Story 0082 -- the validation messages of the dashboard's sales-series actions
// (App\Actions\Dashboard\ResolveSalesBuckets). Story 0083 extends this file with the dashboard's
// UI strings.
return [

    'errors' => [
        'range_invalid' => 'The start date must not be after the end date.',
        'range_too_long' => 'The selected period is too long: it may span at most :max intervals.',
        'statuses_required' => 'Select at least one order status.',
    ],

];
