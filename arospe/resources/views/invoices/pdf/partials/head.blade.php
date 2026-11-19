<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'">
<meta charset="utf-8">
<title>{{ $number }}</title>
<style>
    :root {
        --primary: {{ $primaryColor }};
        --accent: {{ $accentColor }};
    }
    * { box-sizing: border-box; }
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #111827; margin: 0; }
    h1, h2, p { margin: 0; }
    table { width: 100%; border-collapse: collapse; }
    th, td { padding: 5px 6px; text-align: left; vertical-align: top; }
    .num { text-align: right; white-space: nowrap; }
    .logo { max-height: 60px; max-width: 200px; }
    .mark { display: inline-block; border: 2px solid var(--accent); color: var(--accent); padding: 2px 10px; font-weight: bold; letter-spacing: 2px; margin-right: 8px; }
    .muted { color: #6B7280; }
    .parties { margin: 18px 0; }
    .parties td { width: 50%; }
    .totals { width: 45%; margin-left: auto; margin-top: 12px; }
    .totals .grand td { font-weight: bold; border-top: 2px solid var(--primary); }
    .notice { margin: 12px 0; padding: 8px; border: 1px solid var(--accent); }
</style>
