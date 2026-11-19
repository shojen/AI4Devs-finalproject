<table class="lines">
    <thead>
        <tr>
            <th>{{ trans('invoices.pdf.description', [], $locale) }}</th>
            <th>{{ trans('invoices.pdf.sku', [], $locale) }}</th>
            <th class="num">{{ trans('invoices.pdf.quantity', [], $locale) }}</th>
            <th class="num">{{ trans('invoices.pdf.unit_price', [], $locale) }}</th>
            <th class="num">{{ trans('invoices.pdf.line_total', [], $locale) }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ $line['description'] }}</td>
                <td>{{ $line['sku'] }}</td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td class="num">{{ $line['unitPrice'] }}</td>
                <td class="num">{{ $line['lineTotal'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
