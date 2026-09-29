{{--
    Story 0055 D-7 -- renders a stored decimal STRING (decimal(10,2) casts to a string in Eloquent)
    unchanged, with its currency affix and nothing else: no (float) cast, no number_format(), no
    thousands separator, no trimmed trailing zeros. There is no arithmetic anywhere on the orders
    screens -- every figure was already computed by the backend actions -- so a cast here would be
    pure loss (10.00 → 10, 1234.50 → 1234.5). EUR is the only currency (PRD assumption).
--}}
@props(['amount'])
<span {{ $attributes }}>€ {{ $amount }}</span>
