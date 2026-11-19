<table class="parties">
    <tr>
        <td>
            <h2>{{ trans('invoices.pdf.issuer', [], $locale) }}</h2>
            <p>{{ $issuer['name'] }}</p>
            <p>{{ trans('invoices.pdf.tax_id', [], $locale) }}: {{ $issuer['taxId'] }}</p>
            @foreach ($issuer['addressLines'] as $addressLine)
                <p>{{ $addressLine }}</p>
            @endforeach
        </td>
        <td>
            <h2>{{ trans('invoices.pdf.recipient', [], $locale) }}</h2>
            <p>{{ $recipient['name'] }}</p>
            @if ($recipient['taxId'])
                <p>{{ trans('invoices.pdf.tax_id', [], $locale) }}: {{ $recipient['taxId'] }}</p>
            @endif
            @foreach ($recipient['addressLines'] as $addressLine)
                <p>{{ $addressLine }}</p>
            @endforeach
        </td>
    </tr>
</table>
