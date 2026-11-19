<table class="totals">
    <tr>
        <td>{{ trans('invoices.pdf.subtotal', [], $locale) }}</td>
        <td class="num">{{ $subtotal }}</td>
    </tr>
    <tr>
        <td>{{ trans('invoices.pdf.shipping', [], $locale) }}</td>
        <td class="num">{{ $shipping }}</td>
    </tr>
    <tr>
        <td>{{ trans('invoices.pdf.tax', [], $locale) }}@if ($taxRate !== null) ({{ $taxRate }} %)@endif</td>
        <td class="num">{{ $tax }}</td>
    </tr>
    <tr class="grand">
        <td>{{ trans('invoices.pdf.total', [], $locale) }}</td>
        <td class="num">{{ $total }}</td>
    </tr>
</table>
