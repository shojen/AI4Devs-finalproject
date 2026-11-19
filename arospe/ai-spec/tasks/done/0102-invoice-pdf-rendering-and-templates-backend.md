# [0102] Invoice PDF rendering and two templates (backend)

> **Status: Done (closed 2026-11-19, Phase 7).** Epic 6 — Invoicing (ad-hoc; requested by the
> owner on 2026-11-14, not yet in docs/PRD). Blocked on
> [0100](0100-invoice-settings-backend.md) (`InvoiceTemplate`, settings) and
> [0101b](0101b-issue-standard-invoice-backend.md) (`invoices` with its frozen snapshot columns,
> `InvoicePolicy`, `invoices.view`, `lang/{en,es}/invoices.php`). Dependents:
> [0103](../0103-invoice-settings-screen-ui.md), [0104](../in-progress/0104-send-invoice-by-email-backend.md),
> [0109b](../0109b-order-detail-invoices-panel-ui.md), [0110](../0110-billing-section-ui.md).

## Description
Turn an issued invoice into a PDF, on demand, from its frozen snapshot only, with two selectable
templates (Classic and Modern) that carry the same mandatory content, an optional COPY mark, a
corrective layout naming the corrected invoice, and a gated download route. Nothing is stored;
sending by mail is 0104.

Request items served (owner, 2026-11-14, translated):
> 1. ... configures the invoice template: store logo, colours, and which template to use (at
> least two templates must exist) ...
> 2. ... Inside the order the invoice can also be resent or downloaded.
> 3. A return or refund produces a corrective invoice ... which states the invoice it belongs to.

Binding owner decisions:
- U-1 (owner decision, 2026-11-14): PDF engine `spatie/laravel-pdf` with the Browsershot
  (Chromium) driver. The new dependencies are approved: `spatie/laravel-pdf`,
  `spatie/browsershot` and the npm package `puppeteer`.
- Accepted defaults (owner, 2026-11-15; recorded below as questions the owner may overturn):
  the look is frozen at issue (Q-0102-1 (a)); a download carries the COPY mark once the invoice
  has been sent (Q-0102-2 (a)); PDF language = store default language with fallback
  (Q-0102-3 (a)); render on demand, nothing stored (Q-0102-4 (a)).

## Type
backend | includes database-expert: no

## Verified facts that shaped the story
- 0101b stores on each invoice the frozen snapshot: number, type, issue moment, issuer
  (legal name, tax ID, address), recipient (name, tax ID, billing address), `order_number`,
  tax rate and amounts, lines (with `position`), `reason` for correctives, `corrects_invoice_id`,
  and the look: `template`, `primary_color`, `accent_color`, `logo_media_id`.
- `InvoiceTemplate` has `Classic` / `Modern` (0100); invoice numbers may contain `/` (separator
  rule of 0100).
- Store languages: Layer 2 `StoreLanguage` (catalog language, default one marked) is distinct
  from the admin UI locale (`LocaleSetting`, Layer 1); a store language may have no app lang files
  (e.g. `fr`).
- `spatie/laravel-pdf` v2 provides `Pdf::fake()` with view/data/response assertions, so most
  tests need no browser. Support for PHP 8.5 / Laravel 13 is **not documented**.
- Refusals are logged through `LogRefusedPrivilegedAttempt::authorize()`; a route `can:`
  middleware refusal is not logged.

## Decisions
- **FD-1 Install spike first; failure goes back to the owner.** Task 1 installs
  `spatie/laravel-pdf`, `spatie/browsershot` and `puppeteer` (dev dependency), publishes the
  package config, and performs one real render in Sail and in CI. It records the installed
  versions and the exact `Pdf::fake()` assertion names in this file. If the packages do not
  resolve or render on PHP 8.5 / Laravel 13, implementation stops and the engine choice (U-1)
  returns to the owner (options then: pin a fork, wait for support, or another engine); no
  workaround is chosen by the team.
- **FD-2 Snapshot only.** `BuildInvoicePdfData` builds the view data from the `Invoice`, its
  `lines` and its `corrects` invoice only; it never queries `orders`, `customers` or
  `invoice_settings` (the order number is the invoice's own `order_number`). The template used
  is the invoice's `template` column, the colours and logo are its own snapshot.
- **FD-3 "Same content" means same data and same HTML, never same bytes (QA G-2).** A PDF
  embeds a creation time and ids, so two renders of one invoice differ byte-wise. Rendering the
  same invoice with the same copy flag yields identical view data and identical HTML; tests
  compare those.
- **FD-4 Two templates over shared partials.** `classic` and `modern` both `@include` the same
  partials (head, header, parties, lines, totals, corrective notice, copy mark); only layout and
  styling differ, so the mandatory content cannot drift. `InvoiceTemplate::view(): string` is the
  registry. A stored template value that is not a case fails loudly (no silent fallback to
  another look).
- **FD-5 Mandatory content (both templates):** number, issue date, issuer and recipient data
  with tax IDs, lines, taxable base, tax rate, tax, untaxed amount (shipping), total, order
  number. Amounts print the stored values, never recomputed.
- **FD-6 Corrective layout.** Titled as corrective, shows its own number, the corrected
  invoice's number and issue date (read from the frozen `corrects` row) and the translated
  reason (`refund`, `order_edited`, `order_cancelled`); a null reason prints no reason line.
  Negative amounts keep their sign.
- **FD-7 COPY mark.** `RenderInvoicePdf` takes `bool $isCopy`; sending (0104) decides it for
  mails. For downloads the flag is `Invoice::hasBeenSent()` (a `sent` delivery exists); 0102
  ships that method returning `false`, and 0104 implements it with its delivery table and owns
  the "sent invoice downloads as a copy" test (QA G-3). The mark is a translated string in the
  invoice locale.
- **FD-8 Locale.** `ResolveInvoiceLocale` (shipped by 0101b, reused unchanged) returns the default
  `StoreLanguage` code when `lang/{code}/invoices.php` exists, else `config('app.fallback_locale')`; it never reads
  the admin's UI locale. Every string is translated with an explicit locale argument, and the
  ambient `App::getLocale()` is unchanged after a render (queue worker and request differ).
- **FD-9 Money.** `FormatInvoiceMoney` formats the stored decimal string per locale (`1.234,56 €`
  for `es`, `€1,234.56` for `en`) without ever converting to float.
- **FD-10 Hardened render (security).** Every snapshot string prints through Blade `{{ }}`; the
  template folder contains no `{!!`, `<script`, `<link`, `@import`, `url(`, `http:`, `https:`,
  `file:` or I/O `@php` (architecture test). The head partial starts with a static
  `Content-Security-Policy` meta (`default-src 'none'; img-src data:; style-src 'unsafe-inline'`);
  Browsershot runs with JavaScript disabled, a render timeout and
  `--host-resolver-rules=MAP * ~NOTFOUND`, so no file, frame, font or remote host can be loaded.
  System fonts only. Colours are re-checked against `^#[0-9A-Fa-f]{6}$` and emitted only as CSS
  custom properties; an invalid stored value falls back to the template default.
- **FD-11 Logo as a server-built data URI.** `LoadInvoiceLogo` reads the frozen
  `logo_media_id`'s original file (`Media::path`, never the `webp_path`/`avif_path`
  derivatives) from the public disk (refusing `..`, absolute paths or anything outside
  the disk root), accepts only PNG, JPEG or WebP by `finfo` mime (never SVG), up to
  `config('invoicing.pdf.logo_max_bytes')`; otherwise, or when the file is missing, it returns
  null and the PDF renders without a logo.
- **FD-12 Download.** Route `GET invoices/{invoice}/pdf` named `invoices.download`, middleware
  `auth`, `verified` and a named throttle (30 per minute per user, Q-0102-6), **no** `can:`: the
  action `DownloadInvoice` authorizes `view` on the invoice through the logging helper so a
  refusal is logged and returns 403, passing `targetType: 'invoice'` and `targetId: $invoice->id`
  explicitly (the helper auto-resolves only `User`/`Role` targets; as `MarkOrderAsPaid` does) (an unprivileged `can:` refusal would not be logged). The
  file name is `invoice-` + the number with every character outside `[A-Za-z0-9._-]` replaced by
  `-`, plus `.pdf` (the number may contain `/`). A voided invoice, a cancelled order's or a
  soft-deleted customer's invoice stays downloadable.
- **FD-14 Unsaved invoices and the SAMPLE mark (settings preview).** `BuildInvoicePdfData` and
  `RenderInvoicePdf` work on an unsaved `Invoice` whose `lines` relation is set in memory (no
  query, no write); this is used by the settings preview, whose read action `PreviewInvoicePdf` is
  owned by [0103](../0103-invoice-settings-screen-ui.md). Both take `bool $isSample = false`; when
  true the PDF prints a translated "SAMPLE" mark (`invoices.pdf.sample`) on both templates
  (accepted default Q-0103-3 (a)), independently of the COPY mark.
- **FD-13 Paper and timing in config.** `config/invoicing.php` `pdf`: `format` (A4),
  `margin_mm`, `timeout_seconds`, `logo_max_bytes`; plus the package's published config.

## Class contracts
- `App\Enums\InvoiceTemplate::view(): string` (added here).
- `App\Actions\Invoices\RenderInvoicePdf::__invoke(Invoice $invoice, bool $isCopy, bool $isSample =
  false):
  Spatie\LaravelPdf\PdfBuilder` — used by `DownloadInvoice` and 0104.
- `App\Actions\Invoices\BuildInvoicePdfData::__invoke(Invoice $invoice, bool $isCopy, string
  $locale, bool $isSample = false): array<string, mixed>` — pure.
- `App\Actions\Invoices\LoadInvoiceLogo::__invoke(?string $mediaId): ?string` (data URI or null).
- `App\Actions\Invoices\ResolveInvoiceLocale::__invoke(): string`.
- `App\Actions\Invoices\FormatInvoiceMoney::__invoke(string $amount, string $locale): string`.
- `App\Actions\Invoices\DownloadInvoice::__invoke(Invoice $invoice):
  Symfony\Component\HttpFoundation\Response`.
- `App\Http\Controllers\InvoiceDownloadController::__invoke(Invoice $invoice, DownloadInvoice
  $downloadInvoice)` — thin, in front of the action.
- `App\Models\Invoice::hasBeenSent(): bool` — returns `false` here; 0104 completes it.

## Gherkin
```gherkin
Feature: Invoice PDF

  Scenario: A store manager downloads an invoice
    Given a store manager with permission to view invoices, and an invoice issued with the Modern template
    When she downloads it
    Then she receives a PDF showing the number, issue date, issuer and recipient with tax IDs,
      the lines, the tax breakdown, the total and the order number

  Scenario: Both templates show the same mandatory content
    Given a store manager with permission to view invoices, and two invoices of the same order
      issued with the Classic and the Modern template
    When she downloads each of them
    Then both show the same mandatory fields, differing only in layout

  Scenario: An old invoice keeps its original look
    Given a store manager with permission to view invoices, and an invoice issued before the
      logo, colours and template were changed
    When she downloads that invoice
    Then it shows the logo, colours and template it was issued with

  Scenario: A corrective invoice names the invoice it corrects
    Given a store manager with permission to view invoices, and a corrective invoice issued for
      a refund against invoice F2026-00007
    When she downloads the corrective invoice
    Then it is titled as a corrective invoice
    And it names F2026-00007, that invoice's issue date and the reason "refund"

  Scenario: A copy carries the COPY mark
    Given a store manager with permission to view invoices, and an invoice rendered as a copy
    When the PDF is produced
    Then it carries the COPY mark

  Scenario: Customer text is printed, never executed
    Given a store manager with permission to view invoices, and an invoice whose recipient name
      contains HTML tags
    When she downloads it
    Then the name appears as literal text and nothing in it is executed or loaded

  Scenario: The PDF uses the store language
    Given a store manager with permission to view invoices, in a store whose default language is Spanish
    When she downloads an invoice
    Then its labels are in Spanish and amounts are written like "1.234,56 €"

  Scenario: A user without permission cannot download
    Given a user without permission to view invoices
    When she requests an invoice PDF
    Then she receives a refusal
    And the attempt is logged
```

## Files to create/modify
- `composer.json`/`composer.lock` — `spatie/laravel-pdf`, `spatie/browsershot`; `package.json` —
  `puppeteer` (dev).
- `config/invoicing.php` (new), `config/laravel-pdf.php` (published); `.env.example` — the
  `LARAVEL_PDF_*` keys recorded by the spike.
- `app/Enums/InvoiceTemplate.php` — `view()`.
- `app/Models/Invoice.php` — `hasBeenSent()` stub.
- `app/Actions/Invoices/{RenderInvoicePdf, BuildInvoicePdfData, LoadInvoiceLogo,
  FormatInvoiceMoney, DownloadInvoice}.php` (`ResolveInvoiceLocale` already exists from 0101b).
- `app/Http/Controllers/InvoiceDownloadController.php`; `routes/invoices.php` (required from
  `routes/web.php`); the named rate limiter registered in a service provider.
- `resources/views/invoices/pdf/{classic, modern}.blade.php`,
  `resources/views/invoices/pdf/partials/{head, header, parties, lines, totals,
  corrective-notice, copy-mark}.blade.php`.
- `lang/{en,es}/invoices.php` (modified, exist since 0101b) — add group `pdf` (labels, reasons, COPY, SAMPLE, corrective title).
- `.github/workflows/tests.yml` — Node and a Chrome path for the opt-in smoke (devops-engineer).
- Tests below; `tests/Support/Invoices/FakesPdf.php`.

## Tests to perform
Levels: **V** view/data level (render the Blade view or `BuildInvoicePdfData` directly), **F**
`Pdf::fake()`, **H** HTTP through the route under `Pdf::fake()`, **R** real Chromium (one opt-in
test only). Every test in `tests/Feature/Invoices/Pdf/` except the smoke uses `FakesPdf`; a guard
test fails if one does not, so no test starts Chromium by accident.

Happy path
- [ ] T-1 (V) Dataset over `InvoiceTemplate::cases()`: every FD-5 field printed.
- [ ] T-2 (V) `BuildInvoicePdfData` with a query log: no query on `orders`, `customers`,
  `invoice_settings`; the only `media` query is one primary-key lookup inside `LoadInvoiceLogo`
  (none when the logo is null); only snapshot values.
- [ ] T-3 (V) After changing settings, customer and order (dataset of 6 mutations) the data and
  HTML are identical to before; the invoice's own template is used. Top risk.
- [ ] T-4 (V) Same invoice and flag rendered twice a day apart: identical HTML (FD-3).
- [ ] T-5 (V) Corrective for each reason: corrective title, corrected number and date,
  translated reason, negative totals, own number; a standard invoice shows none of it.
- [ ] T-6 (V) `isCopy` true shows the COPY mark in both templates; false does not.
- [ ] T-7 (H) Permitted download: 200, PDF response, attachment with the sanitised name.
- [ ] T-8 (V) Locale dataset: `es` labels and `1.234,56 €`; `en` and `€1,234.56`.
- [ ] T-9 (F) `assertViewIs($template->view())`, data keys, format and margins from config,
  file name; no storage write.
- [ ] T-10b (V) An unsaved invoice with in-memory lines renders in both templates with no query
  (except the single `LoadInvoiceLogo` media lookup when a logo is set) and no write; `isSample = true` prints the SAMPLE mark, false does not; both marks can coexist.
- [ ] T-10 (V) Logo present: `<img>` with a `data:image/...;base64,` source; none without logo.

Edge
- [ ] T-11 (V) Hostile strings (`<script>`, `<img src=x onerror>`, `</style><style>`,
  `{{ 7*7 }}`, `@php ... @endphp`, `url(http://x)`, `"><svg/onload>`, a 10k-character string,
  emoji, RTL) in every text snapshot column: escaped, raw form absent, `{{ 7*7 }}` printed
  literally. Top risk.
- [ ] T-12 Architecture test over the template folder (FD-10) and the CSP meta as the first head
  element.
- [ ] T-13 (V) Colours `#1A2B3C`, `#abcdef`, null ok; stored-bad `red;} body{display:none`,
  `#fff) url(x`, `#12345`, `javascript:` fall back to the default and never appear.
- [ ] T-14 (V, `Storage::fake`) Logo dataset: PNG/JPEG/WebP within the cap -> data URI; SVG, GIF,
  renamed text file, oversize, `../../.env`, `/etc/passwd`, absolute path, missing file, null id
  -> null, no exception.
- [ ] T-15 (V) Money `0.10`, `1234567.89`, `-45.50`, `0.00`, `99999999.99` for `es` and `en`:
  exact strings, no float rounding, consistent sign.
- [ ] T-16 (V) 1 line, 200 lines and a concept line: all lines in `position` order, stored totals
  printed.
- [ ] T-17 (V) Corrective data read from the frozen `corrects` row; null reason prints no reason
  line.
- [ ] T-18 (V, extends `tests/Feature/Invoices/ResolveInvoiceLocaleTest.php`) Default store language `fr` without translations, or none: fallback locale; UI
  locale never read; `App::getLocale()` unchanged after render.
- [ ] T-19 (H) File names for `F2026-00001`, `F/2026/0001`, `INV.2026/7`, `a b`, `..\x`,
  `"quote"`, `é/ü`: only `[A-Za-z0-9._-]`, `.pdf`, no `/`, quote, CR or LF in the header.
- [ ] T-20 (H) `hasBeenSent()` false -> no COPY mark on download (the "sent" case is 0104's).
- [ ] T-21 (H) 31st download by one user within a minute -> 429; another user unaffected.
- [ ] T-22 (H) Voided invoice, cancelled order, soft-deleted customer: still downloadable, same
  content.

Negative
- [ ] T-23 (H) Without `invoices.view` (none, `invoices.create` only, `invoice-settings.manage`
  only): 403, no builder call, one `Log::warning('Privileged action refused')` with `ability =
  view`, `target_type = invoice`, the invoice id.
- [ ] T-24 (H) Guest redirected to login (no privileged-refusal log); unverified user
  redirected to verification.
- [ ] T-25 (H) Unknown or malformed id: 404 for a permitted user.
- [ ] T-26 (H) Super Admin 200; POST/PUT 405.
- [ ] T-27 (H) A refused download reads no order, customer or settings row.
- [ ] T-28 (V) A stored template value outside the enum throws a clear exception.
- [ ] T-29 (V) Logo file removed from storage: renders without logo, amounts intact.
- [ ] T-30 (R, opt-in `INVOICE_PDF_E2E=1`) One real render of a hostile-content invoice: output
  starts with `%PDF-`, more than 1 KB, JavaScript did not run, no local file content embedded,
  finished within `timeout_seconds`. The only proof that the Browsershot hardening applies.

## Expected outcome
A holder of `invoices.view` can download any issued invoice or corrective as a PDF that looks
the way it looked when issued, in the store language, with its mandatory content in either
template; 0104 can render the same PDF, marked as a copy when required, to attach to a mail.

## Acceptance criteria
- [ ] The install spike passed, versions and fake-assertion names are recorded here (FD-1).
- [ ] PDFs are built from the snapshot only (FD-2) and are reproducible in data and HTML (FD-3).
- [ ] Both templates show every FD-5 field; the corrective layout follows FD-6; the COPY mark
  follows FD-7.
- [ ] Locale and money follow FD-8 and FD-9.
- [ ] The render hardening and logo rules hold (FD-10, FD-11), proven by tests T-11..T-14 and
  the smoke T-30.
- [ ] `invoices.download` follows FD-12, refusals logged.
- [ ] en/es `invoices.pdf.*` keys exist with parity.

## Definition of Done
- [ ] Tests written and green (the smoke T-30 run once locally and in CI with Chrome available)
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor) — mandatory review: a headless browser renders data
- [ ] Documentation updated (docs-keeper): `docs/api/routes.md` (`invoices.download`), the
  invoices API doc, authorization (logged download refusal without `can:`), directory structure
  (`resources/views/invoices/pdf/`, `config/invoicing.php`), the operations note below
- [ ] Acceptance criteria met
- [ ] Pint unscoped, Larastan level 7, full suite green

## Dependencies, risks, open technical questions
- Upstream: [0100](0100-invoice-settings-backend.md), [0101b](0101b-issue-standard-invoice-backend.md).
  Downstream: [0104](../in-progress/0104-send-invoice-by-email-backend.md) attaches `RenderInvoicePdf`'s output
  and implements `hasBeenSent()`; [0103](../0103-invoice-settings-screen-ui.md) may preview a sample;
  [0109b](../0109b-order-detail-invoices-panel-ui.md) / [0110](../0110-billing-section-ui.md) link the download.
- **Operations note (runtime prerequisites).** Rendering needs Node 22 or newer on the host that
  renders (web and queue worker), Chrome installed by puppeteer (or an existing Chromium pointed
  to by the package's Chrome-path key), and the package's `LARAVEL_PDF_*` environment keys (Node
  and npm binaries, Chrome path, sandbox flag; exact names recorded by the spike and listed in
  `.env.example`). Running Chrome without its sandbox is acceptable in Sail and CI containers that
  run as root; production must render as an unprivileged user with the sandbox on.
- Risk: PHP 8.5 support is undocumented (FD-1); a failed spike blocks the story and returns U-1
  to the owner.
- Risk: a Chromium start costs seconds and memory; bounded by the throttle, the timeout and the
  absence of user-sized input. Image decoding by Chromium is accepted (trusted uploads, size cap).
- Open technical: whether `Pdf::fake()` can still return the PDF string 0104 needs for the
  attachment (`base64()`/raw output); verify in the spike.
- Open questions recorded for the owner (proceeding on the recommended option; none blocks
  Phase 3 unless the spike fails):
  - Q-0102-1 Look after settings change: (a) frozen at issue (recommended: the document stays
    identical over time); (b) current look.
  - Q-0102-2 COPY mark on download: (a) once the invoice has been sent (recommended: a downloaded
    file may reach the customer); (b) never; (c) always.
  - Q-0102-3 PDF language: (a) store default language with fallback (recommended); (b) the
    acting admin's UI locale.
  - Q-0102-4 Render location: (a) on demand, nothing stored (recommended: reproducible, no
    retention question); (b) render once and store privately; (c) store and attach the stored file.
  - Q-0102-5 A logo used on an issued invoice: (a) cannot be deleted from the gallery
    (recommended; enforced by 0101b's foreign key); (b) deletable, later downloads lose it.
  - Q-0102-6 Download throttle: (a) 30 per minute per user (recommended: bounds Chromium cost,
    far above human use); (b) none.

## Technical tasks
- [ ] Install spike (FD-1): composer and npm packages, published config, one real render in Sail
  and CI; record versions and fake assertions here, or stop and report to the owner.
- [ ] `FormatInvoiceMoney`, `LoadInvoiceLogo` with tests (`ResolveInvoiceLocale` reused; extend its test).
- [ ] `BuildInvoicePdfData` with the zero-query and determinism tests.
- [ ] Templates, partials, CSP head; architecture, escaping and parity tests.
- [ ] `RenderInvoicePdf` with Browsershot hardening; `FakesPdf` helper and guard test.
- [ ] `DownloadInvoice`, controller, route, throttle, file-name sanitiser; authorization tests.
- [ ] `config/invoicing.php`, `.env.example`; CI Chrome path (devops-engineer).
- [ ] Pint, Larastan, targeted then full suite; hand docs items to docs-keeper.

## Phase records
- Phase 1 (Three Amigos) complete on 2026-11-15 — participants: product-owner (facilitator),
  backend-expert, backend-qa.
- Phase 2 (INVEST + docs consistency) — code-reviewer, 2026-11-19: **APPROVED with required
  amendments** (to be applied by product-owner before Phase 3 starts; none changes scope).
  INVEST: Independent (0100/0101b are in `done/`; downstream only consumes the contracts),
  Negotiable (Q-0102-1..6 open with recommended defaults), Valuable (download + 0104 attachment),
  Estimable (the FD-1 spike gates the only unknown and returns U-1 to the owner on failure),
  Testable (30 concrete tests over V/F/H/R levels). Small: large (6 actions, 9 views, 3 deps,
  CI change), but the parts are not separately shippable — templates without a renderer or a
  route deliver nothing, and the spike + FD-14 contract unblock 0103/0104 — so no split; Phase 3
  should follow the technical-task order and commit per layer. Verified against code: `Invoice`
  has `template` (plain string, no enum cast — FD-4's loud failure must come from
  `InvoiceTemplate::from()`), `primary_color`, `accent_color`, `logo_media_id`
  (FK `media`, `restrictOnDelete`, so Q-0102-5 (a) already holds), `order_number`,
  `corrects_invoice_id`/`corrects()`, `reason` (`refund`/`order_edited`/`order_cancelled`);
  `InvoiceTemplate` has only `Classic`/`Modern`; `InvoicePolicy::view` checks `invoices.view`;
  `LogRefusedPrivilegedAttempt::authorize()` exists; `routes/*.php` are `require`d from
  `routes/web.php`; `StoreLanguage::defaultStoreLanguage()` exists. Required amendments:
  1. **`ResolveInvoiceLocale` already exists** (0101b, with
     `tests/Feature/Invoices/ResolveInvoiceLocaleTest.php`, used by `CreateStandardInvoice`).
     Remove it from "Files to create" and the technical task "`ResolveInvoiceLocale` ... with
     tests"; mark it "reused". Reword FD-8 to its real rule — default store language when
     `lang/{code}/invoices.php` exists, else `app.fallback_locale` (not "has `invoices.pdf.*`
     translations"); T-18 extends the existing test file rather than a new one.
  2. **T-2 / T-10b contradict FD-11.** `LoadInvoiceLogo(?string $mediaId)` must read the
     `media` row to find the file, so a render with a logo queries `media`. Restate T-2 as "the
     only `media` query is one primary-key lookup inside `LoadInvoiceLogo`", and T-10b's "no
     query" as "no query except that logo lookup when `logo_media_id` is set" (or test it with a
     null logo).
  3. **FD-11 must name which file.** `Media` stores `path` (original), `webp_path` and
     `avif_path`; state which one is read (recommended: `path`, the original, mime-checked; AVIF
     is outside the accepted list).
  4. **FD-12 / T-23 logging call:** the helper auto-resolves only `User`/`Role` targets, so
     `DownloadInvoice` must pass `targetType: 'invoice'`, `targetId: $invoice->id` explicitly
     (as `MarkOrderAsPaid` does); add that to the class contract or FD-12.
  5. `lang/{en,es}/invoices.php` already exist (0101b): list them as **modified** (add the
     `pdf` group), not created. Minor: the Gherkin actor "sales assistant" is not a seeded role;
     the test should build a user without `invoices.view` (per T-23's dataset).
- Phase 3, task 1 (FD-1 install spike) — PASSED 2026-11-19. Installed `spatie/laravel-pdf`
  2.14.0, `spatie/browsershot` 5.5.0 and npm `puppeteer` ^25.18.1 (dev) on PHP 8.5.0 /
  Laravel 13 / Node 24. A real render (`Pdf::html(...)->format('a4')->base64()`) returned a valid
  `%PDF-` of about 7.6 KB once `npx puppeteer browsers install chrome-headless-shell` had run
  (Browsershot needs the headless shell matching puppeteer; `LARAVEL_PDF_NO_SANDBOX=true` for
  root containers). Package config published to `config/laravel-pdf.php`; env keys:
  `LARAVEL_PDF_DRIVER`, `_NODE_BINARY`, `_NPM_BINARY`, `_CHROME_PATH`, `_NODE_MODULES_PATH`,
  `_NO_SANDBOX` (listed in `.env.example`). `Pdf::fake()` returns `FakePdfBuilder` with
  `assertViewIs`, `assertViewHas`, `assertSee`, `assertDontSee`, `assertSaved`,
  `assertBrowsershot`, `assertRespondedWithPdf`, `assertQueued`, `assertNotQueued`;
  `generatePdfContent()` and `base64()` still return dummy PDF content under the fake, so 0104 can
  attach it in tests. Side effect: composer's `boost:update` hook rewrote `CLAUDE.md`; it was
  restored from git and must not be committed.
- Phase 4 (security audit) — appsec-auditor, 2026-11-19: **CHANGES REQUESTED (1 Medium, return
  to Phase 3)**. Verified safe: every snapshot string is Blade-escaped; no `{!!`/script/link/url()
  in the templates (architecture test); static CSP meta is the first `<head>` element; JS disabled,
  timeout and `host-resolver-rules=MAP * ~NOTFOUND`; colours re-validated before the CSS context;
  logo path from the `media` row only, `..`/absolute/NUL refused, `finfo` allow-list without SVG,
  size cap; `view` authorized through `LogRefusedPrivilegedAttempt` (403 logged, 404 for unknown
  ids, guest redirect unlogged); file name restricted to `[A-Za-z0-9._-]` (no header injection);
  30/min per-user throttle; `no_sandbox` env-only default false (true only in the root CI job);
  `write_options_to_file` true; PDF cache off. `composer audit` / `npm audit`: no advisory for
  spatie/laravel-pdf 2.14.0, spatie/browsershot 5.5.0 or puppeteer 25.18.1 (other advisories are
  pre-existing and out of scope).
  - **M-1 (Medium, availability / business logic, CWE-20):** `BrowsershotDriver` always calls
    `Browsershot::html($html)`, whose guard throws `HtmlIsNotAllowedToContainFile` when the HTML
    (raw or entity-decoded) contains `view-source` or `//localhost`/`//127.`-style text. Reproduced:
    `Browsershot::html('<p>Piso 2 view-source</p>')` throws. Customer-controlled snapshot strings
    (`recipient_name` from the customer, billing address, product names) reach the template, so a
    customer can make their own issued (immutable) invoice permanently unrenderable (500 on
    download, later a failed 0104 attachment). Fix: rebind `laravel-pdf.driver.browsershot` to a
    `BrowsershotDriver` subclass that writes the HTML to an app-owned private temp file and loads it
    with `setHtmlFromFilePath()` **(recommended)**, keeping every FD-10 layer and the
    `withBrowsershot` callback; add a test with `view-source` and `//127.0.0.1` in the billing address
    and a product name (real-Chromium smoke test plus a driver-level assertion that no exception is
    raised). Alternative: neutralise those tokens with a U+2060 word joiner before rendering
    (alters the legal text; not recommended).
  - **L-1 (Low, information exposure, CWE-377):** Browsershot's temp HTML (customer PII) is written
    under the system temp dir with `spatie/temporary-directory`'s 0777-minus-umask mode. Set
    `LARAVEL_PDF_TEMP_PATH` to a private storage directory (document in `.env.example`); the M-1
    driver should use the same private directory.
  - **L-2 (Low, DoS, CWE-400):** the throttle bounds rate, not concurrency; 30 parallel requests per
    user each spawn a Chromium. Consider a `Cache::lock`/funnel concurrency cap around the render
    (non-blocking; acceptable for an admin-only route if deferred).
  - **I-1 (Info):** the driver is env-selectable (`LARAVEL_PDF_DRIVER`); the hardening lives only in
    `withBrowsershot`, so another driver (e.g. cloudflare, which sends PII to a third party) would
    silently drop it. Consider pinning `'driver' => 'browsershot'` or asserting it in
    `RenderInvoicePdf`. **I-2:** puppeteer is a devDependency; a production `npm ci --omit=dev`
    has no renderer, and Chromium security patches arrive only with puppeteer upgrades — keep it
    current. **I-3:** add `Cache-Control: private, no-store` to the PDF response (PII).
  - Durable rules recorded in `docs/security/headless-pdf-rendering.md`.
- Phase 4 re-audit — appsec-auditor, 2026-11-19 (commits 7acfa24, 38f128c): **PASS, with one
  Low recommendation (N-1)**. M-1 fixed: `SafeBrowsershotDriver` passes `''` to the parent (guard
  never sees the data) and loads the HTML with `setHtmlFromFilePath()` before the
  `withBrowsershot` callback; the file is removed in a `finally` of both `generatePdf()`/`savePdf()`,
  the only parent paths that call `buildBrowsershot()`. L-1 fixed: 0600 file (umask 0077 at
  creation, no window), random 128-bit name, 0700 directory, default
  `storage/app/private/pdf-temp` (gitignored, outside the web root); Browsershot's own temp
  directory uses the same path. I-1 fixed: `->driver('browsershot')` resolves the rebound container
  key (package providers register before app providers; binding test confirms the subclass).
  I-3 fixed: `Cache-Control: private, no-store`. L-2 deferred and I-2 an operations note, as decided.
  - **N-1 (Low, defense in depth, CWE-552) — new, introduced by the fix:** Browsershot sets the
    puppeteer option `blockLocalFileAccess` only when `$this->html !== ''` (`createCommand()`), so
    the file-based load silently drops the interception that aborts every `file:` request except
    the initial navigation. Local-file reads stay blocked by Blade escaping, the template
    architecture test and the CSP meta (`default-src 'none'; img-src data:`), so not exploitable
    today; but one regression in any of those would now let `<iframe src="file:///…/.env">` reach
    the PDF. Recommendation: in the `SafeBrowsershotDriver` wrapper closure, after
    `setHtmlFromFilePath($filePath)`, add `$browsershot->setOption('blockLocalFileAccess', true);`
    and assert it in `SafeBrowsershotDriverTest` (read `additionalOptions`). Cheap; fix in this
    story if possible.
  - **N-2 (Info):** the `finally` cleanup does not run if the PHP process dies (OOM, SIGKILL, fatal);
    a PII HTML file can then remain in the 0700 directory. Optional: a scheduled prune of
    `pdf-temp` files older than a few minutes.
- Phase 5 (final code review) — code-reviewer, 2026-11-19 (diff `origin/main...HEAD`, through
  ff6ff7d): **APPROVED, non-blocking notes only.** Verified: every acceptance criterion and
  FD-1..FD-14 is implemented and covered (T-1..T-30; T-10b/T-2 logo-lookup amendment honoured;
  N-1 fixed by `setOption('blockLocalFileAccess', true)` in `SafeBrowsershotDriver.php:58`, asserted
  in `SafeBrowsershotDriverTest`); thin controller in front of `DownloadInvoice`; refusal logged with
  explicit `targetType`/`targetId`; route has no `can:`; en/es `invoices.pdf.*` key parity (25 keys
  each, programmatic diff empty); guard works (Pest.php `afterEach` + source scan + negative test).
  Re-run by reviewer: `tests/Feature/Invoices/Pdf`, `ResolveInvoiceLocaleTest`,
  `InvoicePdfTemplatesArchitectureTest` 185 passed / 2 skipped (opt-in smoke); Larastan on the
  touched paths 0 errors. Full suite, Pint and the real smoke taken from the orchestrator's evidence.
  Non-blocking notes (may be fixed now or left; none changes behaviour):
  1. `app/Actions/Invoices/BuildInvoicePdfData.php:26` returns `array<string, mixed>`; code-style's
     "PHPDoc array shapes" rule prefers an `array{locale: string, isCopy: bool, ...}` shape for this
     fixed-key view model (the story's class contract also says `array<string, mixed>`, so not a
     violation of the story).
  2. `BuildInvoicePdfData.php:79` `date(mixed $moment, ...)` — type it `CarbonInterface` (the cast is
     `datetime`); `mixed` hides the `->format()` call from Larastan.
  3. `app/Providers/AppServiceProvider.php:38-39` registers the limiter inline in `boot()`; siblings use
     a named `configure*()` method (`FortifyServiceProvider::configureRateLimiting()`).
  4. `app/Actions/Invoices/DownloadInvoice.php:29` uses the `request()` helper inside an action;
     consider letting the controller pass the `Request` (or call `toResponse()` there).
  5. `app/Actions/Invoices/SafeBrowsershotDriver.php` is a package-driver subclass, not an action;
     acceptable under the no-new-folder rule, but docs-keeper should record it in
     `directory-structure/actions.md` so the placement reads as deliberate.
  6. `tests/Feature/Invoices/Pdf/InvoicePdfSmokeTest.php:30-31` — `not->toContain('root:x:0:0')` cannot
     fail (Chrome's page content streams are Flate-compressed), and the script is Blade-escaped so
     "JS did not run" holds even with JavaScript enabled. Hardening is proven by the option-level
     assertions (`RenderInvoicePdfTest` FD-10, `SafeBrowsershotDriverTest` N-1); the smoke proves a
     real, bounded render. Accepted as is; do not cite T-30 as proof of the JS/file blocking.
  Remaining DoD for later phases: documentation (Phase 6) and the closing full-suite gate (Phase 7).
- Phase 6 (documentation) — docs-keeper, 2026-11-19: **DONE**. Updated `docs/api/routes.md` and `docs/api/invoices.md` (`invoices.download`, the PDF actions, templates, marks, config), the authorization refusal-logging part (logged refusal without `can:`, explicit `targetType`/`targetId`), `docs/conventions/directory-structure*` (`resources/views/invoices/pdf/`, `config/invoicing.php`, `routes/invoices.php`, `FakesPdf`, and why `SafeBrowsershotDriver` sits in `app/Actions/Invoices/`), `docs/conventions/localization.md` (`invoices.pdf.*`, PDF language rule), `docs/architecture/overview.md` (deployment prerequisites: Node 22+, puppeteer devDependency, private temp directory, sandbox flag, env keys), `docs/testing` (`FakesPdf` guard, opt-in smoke, CI steps), `docs/security/headless-pdf-rendering.md` and its index row, the `skipOnCi()` exception in `docs/contracts/ci-protocol.md`, three `docs/errors-log.md` entries, `docs/README.md` and the root `README.md` (Node/puppeteer setup). The Phase 5 non-blocking notes 1-4 and 6 stay follow-ups here only (nothing was added to `docs/`); note 5 is resolved by the directory-structure entry.
- Phase 7 (closure) — 2026-11-19: full suite green in directory chunks (Unit + Orders + Localization + Customers + Invoices + InvoiceSettings 2526 passed / 2 skipped; Blog + Products + ProductCategories 2113 / 2113; remaining Feature 2463 passed plus `TopbarTest` repaired, Layout 75 / 75; Browser 330 passed / 3 skipped); Pint unscoped clean; Larastan level 7 clean on the touched paths. File moved to `done/` with the two-direction link check and the `tasks-map.md` / `tasks-status.json` regeneration. Dependents: `0103` and `0104` are now `ready`; `0109b` and `0110` stay `blocked` on their other dependencies.
