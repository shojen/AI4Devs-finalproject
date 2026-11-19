# Headless-browser PDF rendering

Rules for rendering application data to PDF through a headless Chromium (spatie/laravel-pdf +
spatie/browsershot + puppeteer), established by the story 0102 Phase 4 audit. Chromium is a full
browser running on the server: anything it can fetch, the PDF can disclose (SSRF, `file://` reads).

## Rule 1 — layered render hardening (confirmed-safe pattern)

All four layers are required; none is sufficient alone.

1. **Output encoding.** Every data string prints through Blade `{{ }}`. The template folder holds
   no `{!!`, `<script`, `<link`, `@import`, `url(`, `http:`, `https:`, `file:` or `@php`
   (enforced by `tests/Unit/InvoicePdfTemplatesArchitectureTest.php`).
2. **Static CSP meta as the first `<head>` element** — `resources/views/invoices/pdf/partials/head.blade.php`:

   ```html
   <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; style-src 'unsafe-inline'">
   ```

   It must precede every other element so no resource can load before it applies.
3. **Browser options** — `app/Actions/Invoices/RenderInvoicePdf.php`:

   ```php
   $browsershot->disableJavascript()
       ->timeout($timeoutMilliseconds)
       ->addChromiumArguments(['host-resolver-rules' => 'MAP * ~NOTFOUND']);
   ```

4. **Images only as server-built data URIs** (`app/Actions/Invoices/LoadInvoiceLogo.php`): the path
   comes from the `media` row (never request input), `..`/absolute/NUL paths are refused, the mime
   is sniffed with `finfo` against a PNG/JPEG/WebP allow-list (never SVG), and the size is capped.

A value printed inside a `<style>` block (a CSS context, where HTML escaping does not help) must be
re-validated against a closed format at render time — `BuildInvoicePdfData::color()` re-checks
`^#[0-9A-Fa-f]{6}$` and falls back to a default.

Operational: `LARAVEL_PDF_NO_SANDBOX` is env-only, default `false`, and set to `true` only in root
containers (CI). `write_options_to_file` stays `true` so options never travel on the command line.

## Rule 2 — Browsershot's `setHtml()` string guard is not a security control, and it can deny service

`Browsershot::html()`/`setHtml()` (5.5.0) throws `HtmlIsNotAllowedToContainFile` when the HTML —
raw or after `html_entity_decode()` — contains `view-source` (any case) or a `//localhost`,
`//127.`, `//0.0.0.0`, `//[::1]` pattern. spatie/laravel-pdf's `BrowsershotDriver::buildBrowsershot()`
always calls `Browsershot::html($html)` **before** any `withBrowsershot()` callback runs.

Consequence: any customer-controlled string that reaches the template (customer name, billing
address, product name) and contains `view-source` makes the render throw a 500. For an immutable
document (an issued invoice) that means the document can never be rendered. Blade escaping does
not help — the guard also checks the entity-decoded form.

Do not rely on this guard for safety (Rule 1 is the control), and do not let it decide
availability.

## Rule 3 — the final design: file-based loading through a pinned driver (confirmed-safe pattern)

- **File-based loading.** `app/Actions/Invoices/SafeBrowsershotDriver.php` extends
  `BrowsershotDriver` and is bound to `laravel-pdf.driver.browsershot` in
  `AppServiceProvider::register()` (package providers register first, so the app binding wins). It
  passes `''` to the parent's `buildBrowsershot()` and wraps the customise callback so
  `setHtmlFromFilePath($file)` runs before the `withBrowsershot()` hardening callback. The file is
  deleted in a `finally` in both `generatePdf()` and `savePdf()` — the only parent paths that build
  a Browsershot — so a failed render leaves nothing behind (a killed PHP process still can; prune
  the directory if that matters).
- **Private temp file.** The file is created under `umask(0077)` (0600 from the first byte, no
  chmod window) with a random 128-bit name, in a 0700 directory. `LARAVEL_PDF_TEMP_PATH` defaults to
  `storage/app/private/pdf-temp` (gitignored, outside the web root); Browsershot's own temp files
  use the same directory. A pre-existing directory is not re-chmodded — create it 0700 on deploy.
- **Re-enable local-file blocking.** Browsershot only sets the puppeteer `blockLocalFileAccess`
  option when it loaded the page from a string (`$this->html !== ''`), so a file-based load loses
  the interception that aborts every `file:` request except the initial navigation. Set it
  explicitly (`$browsershot->setOption('blockLocalFileAccess', true)`) next to
  `setHtmlFromFilePath()`; the Rule 1 CSP still blocks those requests, but this keeps the layer.
- **Pinned driver.** `RenderInvoicePdf` calls `->driver('browsershot')`, so `LARAVEL_PDF_DRIVER`
  cannot route invoices to a driver without the Rule 1 options (or to a third-party renderer).
- **No caching of the result.** The download response carries `Cache-Control: private, no-store`.
- **Deferred:** a concurrency cap on renders (each one spawns a Chromium). Acceptable while the only
  caller is the admin-only, per-user-throttled download route; revisit when renders become
  customer-triggered or queued in bulk.

Cover it with a test whose data contains `view-source`/`//127.0.0.1`, a test that the temp file is
0600 and gone after a failed render, and a test that the binding resolves to the subclass.

_Last updated: 2026-11-19 — story 0102 Phase 4 re-audit: Rule 3 describes the final file-based design and the `blockLocalFileAccess` gap._
