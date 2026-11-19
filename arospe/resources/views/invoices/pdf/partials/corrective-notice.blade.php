@if ($isCorrective)
    <div class="notice">
        <p>{{ trans('invoices.pdf.corrects', [], $locale) }}: {{ $correctedNumber }} ({{ $correctedIssuedOn }})</p>
        @if ($reasonKey)
            <p>{{ trans('invoices.pdf.reason', [], $locale) }}: {{ trans('invoices.pdf.reasons.'.$reasonKey, [], $locale) }}</p>
        @endif
    </div>
@endif
