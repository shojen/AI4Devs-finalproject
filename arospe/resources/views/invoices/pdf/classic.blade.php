<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
@include('invoices.pdf.partials.head')
<style>
    th { border-bottom: 2px solid #111827; }
    td { border-bottom: 1px solid #E5E7EB; }
    h1 { font-family: Georgia, serif; font-size: 22px; text-transform: uppercase; }
</style>
</head>
<body>
@include('invoices.pdf.partials.copy-mark')
@include('invoices.pdf.partials.header')
@include('invoices.pdf.partials.corrective-notice')
@include('invoices.pdf.partials.parties')
@include('invoices.pdf.partials.lines')
@include('invoices.pdf.partials.totals')
</body>
</html>
