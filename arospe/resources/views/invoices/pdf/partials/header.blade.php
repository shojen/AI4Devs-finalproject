<table class="header">
    <tr>
        <td>
            @if ($logo)
                <img class="logo" src="{{ $logo }}" alt="">
            @endif
        </td>
        <td class="num">
            <h1>{{ $isCorrective ? trans('invoices.pdf.corrective_title', [], $locale) : trans('invoices.pdf.title', [], $locale) }}</h1>
            <p>{{ trans('invoices.pdf.number', [], $locale) }}: {{ $number }}</p>
            <p>{{ trans('invoices.pdf.issued_on', [], $locale) }}: {{ $issuedOn }}</p>
            <p>{{ trans('invoices.pdf.order', [], $locale) }}: {{ $orderNumber }}</p>
        </td>
    </tr>
</table>
