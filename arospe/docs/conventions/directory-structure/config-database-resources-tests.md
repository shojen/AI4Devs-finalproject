# Directory Structure — config, database, lang, resources, routes, tests

> Part of [Directory Structure](../directory-structure.md). **Read this part when:** you add a config file, seeder/factory/fixture, lang file, Blade view/component, route file or test and need to know where it goes. The other parts are listed in the [hub](../directory-structure.md#table-of-contents).

### `config/`, `database/`, `lang/`, `resources/`, `routes/` and `tests/`

```
config/                Laravel + package config (fortify.php, permission.php,
                        intervention-image.php, livewire.php, ...), plus modules.php — the one
                        app-owned config file (see below)
database/
  data/                 Bundled, version-controlled fixture data a seeder reads — not seeder
                        classes (iso-3166-countries.json, shared read-only with story 0032's own
                        GeographyCatalogSeeder; es-municipalities.csv, story 0032's ~8,130-row
                        Spanish municipio fixture; plus its own README stating provenance for both)
  factories/
  migrations/
  seeders/
lang/                   Published translation files, one folder per locale (en/, es/), plus
                        app-owned domain files kept key-for-key identical across both
                        (users.php, roles.php, navigation.php, sales-regions.php, media.php,
                        components.php, products.php — the latter's categories.index subgroup is
                        story 0025's copy for the product categories screen; shipping.php;
                        payment-methods.php since story 0038; customers.php since story 0044 —
                        the file 0041 deliberately deferred to this story, D-14; orders.php since
                        story 0045 — statuses/payment_statuses, errors, transitions, refunds, cancellation,
                        flag_reasons, then (story 0055) the screen copy: index, detail, line_items, lifecycle;
                        en/es key parity pinned by tests/Feature/Orders/OrdersLangParityTest.php;
                        blog.php (story 0061's category delete-blocked message and 0062's categories.index copy),
                        blog-tags.php (story 0060) and blog-posts.php (story 0063 — index.*, editor.*, statuses.*,
                        its own file rather than an extension of blog.php), each key-for-key identical across en/es)
resources/
  views/
    components/        Blade components — all anonymous (no app/View/Components/ in this repo). Story
                        0055 added the first two shared ones that are not navigation chrome: money.blade.php
                        (`<x-money :amount>`, `€ {amount}` with no cast) and confirm-dialog.blade.php
                        (a stateless confirmation dialog whose parent owns the flag and both methods)
    layouts/            Auth/app layout shells
    livewire/           Views for Livewire components AND plain auth Blade views (see naming.md).
                        products/variant-builder.blade.php (story 0031) is the ordinary mirror-rule
                        pairing for Products/VariantBuilder.php above (naming.md's exception does not
                        apply — the class is not named Index), embedded from
                        products/editor.blade.php below a flux:separator, rendered only when
                        $productId !== null
    partials/
routes/                 web.php, plus one file per functional area that web.php requires
                        (settings.php, roles.php, users.php, sales-regions.php,
                        product-categories.php, product-attribute-types.php, products.php,
                        shipping.php, payment-methods.php, customers.php since story 0044, orders.php since story 0055, blog-tags.php,
                        blog-categories.php and blog-posts.php since stories 0060, 0062 and 0063),
                        plus console.php since story 0064 — NOT an area file: loaded by the console kernel,
                        registers no HTTP route, holds the `Schedule::` entries — no
                        api.php yet. web.php no longer
                        holds the story 0020/0021 environment-gated dev route (story 0020's
                        browser-test harness) — story 0027 retired it once Products/Editor
                        supplied a real host page; see ../api/routes.md
tests/
  Feature/              Feature tests, mirrors app structure (Actions/Auth/, Auth/, Settings/, Console/Commands/
                        since story 0064 — mirrors app/Console/Commands/ — and Console/ beside it for the
                        schedule-registration test, which mirrors nothing in app/; shared sweep fixtures live in
                        tests/Support/Blog/,
                        Seeders/, Users/, Roles/, SalesRegions/, Media/, ProductCategories/,
                        Products/, Components/, Models/, Policies/, Authorization/,
                        Navigation/, PaymentMethods/ since story 0038 — the story's own
                        Datasets.php holds the valid/invalid IBAN pairs shared across its four
                        test files, per Pest's own directory-scoped dataset convention (see
                        tests/Feature/ShippingRates/Datasets.php for the mechanism)). Dev/
                        (story 0020's MediaGalleryHarnessRouteTest.php) was
                        deleted by story 0027 along with its subject. Story 0031 adds five files to
                        Products/ for the nested VariantBuilder component, of which
                        VariantBuilderTest.php (create/duplicate-combination/sku-collision refusal/
                        delete/re-create round trip) and VariantBuilderAuthorizationTest.php (an
                        allow-and-deny pair per gated method, matching D-10's six-call-site fixture)
                        are the two to read first; VariantBuilderQueryTest.php pins the
                        featuredImage/values.type eager-load with no N+1 as either axis grows,
                        VariantBuilderRenderingTest.php covers refusal placement, the disabled-row
                        hints and the empty-state/no-attribute-types dead ends, and
                        VariantBuilderSkuPreviewTest.php pins the live #[Computed] preview against
                        0029's own derivation formula
  Unit/                 Mirrors app structure too (Actions/ itself — NormalizeForSearchTest.php,
                        story 0022, sits directly here with no subfolder, matching the app class it
                        tests — plus Actions/Auth/, Actions/Media/, Actions/Products/ (story 0029's
                        HashVariantCombinationTest.php/DeriveVariantSkuTest.php, unit-testing the two
                        pure-function collaborators directly rather than only through the actions
                        that inject them), Concerns/ (story 0023's
                        BlogCategoryValidationRulesTest.php (story 0058), BlogTagValidationRulesTest.php (story 0059), BlogPostValidationRulesTest.php (story 0061), ProductCategoryValidationRulesTest.php, the first trait-level unit test in
                        this folder, joined by story 0024's ProductValidationRulesTest.php and
                        story 0038's PaymentMethodValidationRulesTest.php, which drives the real
                        Iban rule through ibanRules() rather than re-deriving mod-97 as abstract
                        math),
                        Enums/ (ProductStatusTest.php / ProductTypeTest.php since story 0024;
                        PaymentMethodCodeTest.php since story 0038),
                        Exceptions/, Listeners/, Models/, Seeders/ (story 0032's
                        GeographyCatalogSeederParsingTest.php — the CSV-parsing generator exercised
                        via reflection, no database — and GeographyFixtureIntegrityTest.php, which
                        parses the real bundled fixtures directly, its own file so a fast run can
                        exclude it), plus ArchitectureTest.php
  Support/              Test-only support code, not app code (story 0022, the suite's first use of
                        this base folder) — Livewire/ArrayMultiSelectOptionsResolver.php,
                        a conforming MultiSelectOptionsResolver double three later stories (0026,
                        0027, 0034) pattern-match their real resolvers against, and (story 0032)
                        Seeders/TestableGeographyCatalogSeeder.php, a GeographyCatalogSeeder
                        subclass whose fixture paths redirect to tests/Fixtures/geography/ — the
                        real seeder's own fixturePath() override hook exists specifically so this
                        test double can exist; autoloaded via composer.json's existing
                        "Tests\\": "tests/" mapping, no new autoload entry
  Browser/              Pest browser tests. Mirrors app structure (Auth/, Media/, Components/,
                        Products/ since story 0027) — but four of the eleven files sit flat instead
                        (UsersIndexTest.php, RolesIndexTest.php, SalesRegionsIndexTest.php,
                        ProductCategoriesIndexTest.php; the previous "three of eight" count here
                        missed ProductCategoriesIndexTest.php entirely, a gap present since story
                        0025 and corrected by story 0027's own pass); see
                        ../../testing/frontend/playwright-setup/status-structure-and-syntax.md#folder-structure. Story 0055 adds
                        Browser/Orders/ (six files, one per concern), Browser/BlogPosts/ (story 0063: IndexTest.php and
                        EditorJourneyTest.php, beside 0060's and 0062's Browser/BlogTags/ and Browser/BlogCategories/)
                        and Support/Orders/OrdersUi.php
                        (shared permission profiles and rendered-HTML probes, a class of static methods
                        rather than global Pest helpers, which would redeclare-fatal across the many
                        Feature/Orders files)
  Browser/Fixtures/     Real, checked-in binary fixtures a browser test needs as bytes on disk
                        (sample-upload.jpg) — never generated at runtime
  Fixtures/geography/   Real, checked-in CSV fixtures for story 0032's seeder tests — a small
                        (521-row, all 17 comunidades represented) municipality CSV plus malformed/
                        duplicate/quoting variants, so a test never has to seed the whole ~8,300-row
                        real catalog to exercise the seeder's own logic
  Pest.php, TestCase.php
```
