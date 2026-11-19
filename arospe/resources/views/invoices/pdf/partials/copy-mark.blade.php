@if ($isSample || $isCopy)
    <p>
        @if ($isSample)
            <span class="mark">{{ trans('invoices.pdf.sample', [], $locale) }}</span>
        @endif
        @if ($isCopy)
            <span class="mark">{{ trans('invoices.pdf.copy', [], $locale) }}</span>
        @endif
    </p>
@endif
