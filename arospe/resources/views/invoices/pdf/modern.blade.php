<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
@include('invoices.pdf.partials.head')
<style>
    body { border-top: 10px solid var(--primary); padding-top: 12px; }
    th { background: var(--primary); color: #FFFFFF; }
    td { border-bottom: none; }
    h1 { font-size: 26px; color: var(--primary); }
    h2 { color: var(--accent); font-size: 12px; }
</style>
</head>
<body>
@include('invoices.pdf.partials.header')
@include('invoices.pdf.partials.copy-mark')
@include('invoices.pdf.partials.corrective-notice')
@include('invoices.pdf.partials.parties')
@include('invoices.pdf.partials.lines')
@include('invoices.pdf.partials.totals')
</body>
</html>
