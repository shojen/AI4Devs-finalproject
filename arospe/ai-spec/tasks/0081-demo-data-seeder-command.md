# [0081] Demo data seeder command

## Description
A developer or store operator needs a single Artisan command that fills a local or demo
environment with realistic-looking data (via Faker): customers, orders, products, product
categories, blog posts, blog categories and blog tags — exactly 10 of each, except orders — with
every relationship wired to already-created rows so nothing depends on a row that does not exist
yet: products belong to already-seeded categories, every customer has one or more orders (so no
customer is left order-less) built entirely from the already-seeded product catalog, every blog
post is authored by an already-existing user, and some tags are attached to posts from an
already-seeded tag pool. Rows are created in an explicit dependency order (users/categories →
products/customers → orders → blog categories/tags → blog posts) so nothing can reference a row
that has not been created yet. The command refuses to run outside `local`/`testing` unless
`--force` is given.

Standalone story (not part of a PRD epic). Requested by the project owner (original request in
Spanish): "Create an Artisan command that runs a seeder to generate demo data: customers, orders,
products, product categories (assigned to products), blog posts, blog categories, and blog tags
(with some tags assigned to posts). At least 10 of each, with realistic-looking data generated via
Faker."

## Type
backend | includes database-expert: no

No frontend, no migration, no model or schema change: every needed model and factory already
exists (`Customer`, `ProductCategory`, `Product`, `Order`, `OrderItem`, `BlogCategory`, `BlogTag`,
`BlogPost`). `database-expert` did not take part in the debate for that reason.

## Documented functional decisions

### D-1 — Allow-listed environments are `local` and `testing` only; everything else needs `--force`
The command runs unconditionally in `local` and `testing`. In any other environment (including
`production` and any future `staging`/`demo`) it aborts with a clear error unless `--force` is
passed.
**Reasoning:** matches `DatabaseSeeder.php`'s existing N4 precedent
(`app()->environment(['local', 'testing'])`) exactly. No other named environment is documented
anywhere in this repo today, and requiring `--force` elsewhere is the safer default — it costs one
flag, not a blocked path, and mirrors Laravel's own `db:seed --force` idiom (a demo/staging box
can still be seeded on purpose).

### D-2 — Runs are additive, not idempotent
Each run adds another batch — exactly 10 more of every entity except orders (which add ≥10 more,
per D-4) — on top of whatever already exists. No truncation, no reset, no "demo data" marker.
A Faker-uniqueness collision across separate runs (separate processes) is a known, accepted
limitation of this decision, not something the command defends against — see Dependencies/risks.
**Reasoning:** there is no "seed twice safely" precedent anywhere in this codebase (even
`DatabaseSeeder`'s single demo user would collide with itself on its unique email if re-run). A
reset/truncate step would be materially riskier scope — it would have to tell demo rows from real
ones with no schema support — and it is not part of the original request. Recorded as a known
limitation under Dependencies/risks, not a blocker.

### D-3 — Demo rows are not distinguishable from real rows
No `is_demo` flag or any other schema change is part of this story. Faker's randomized
names/emails are the only implicit "tell".
**Reasoning:** out of scope for the original request, and it would require a schema change across
seven tables. Recorded as a limitation.

### D-4 — Exactly 10 of every named entity except orders, which are driven off the customer pool
Exactly 10 each of customers, products, product categories, blog posts, blog categories and blog
tags (the request says "at least 10"; exactly 10 satisfies it and is deterministic and simple to
assert). **Orders are not fixed at 10**: per the project owner's follow-up feedback, every seeded
customer must have one or more orders, so each of the 10 customers gets a random 1–3 orders
instead — the total order count therefore varies (10 at minimum, ~20 on average) but is always
≥10, still satisfying the original "at least 10" request while guaranteeing no order-less
customer. Order items per order (1–4) and tags per post (0–3; the first post always tagged, every
other post tagged ~70% of the time) vary,
since those are realism details of relationships, not entities the request named a fixed count
for. No `--count` option.

### D-5 — Blog posts are authored by an existing user, reusing one if present
Every seeded blog post's `created_by` references a real, existing `User` row — never `null` (the
`BlogPostFactory` default) and never a freshly bulk-created user pool. The seeder resolves a small
creator pool by reusing **every** `User` row already in the database, if any exist; if the
database has none at all (a genuinely empty install), it creates exactly **one** fallback user via
`User::factory()->create()` so the command never fails for lack of an author. Each blog post picks
its author randomly from that pool.
**Reasoning:** per the project owner's follow-up feedback ("los posts deben de pertenecer a un
usuario existente"). This does not reopen "Out of scope"'s "no bulk-seeding users" rule — it is the
minimal amount of user data needed to satisfy `blog_posts.created_by`'s real-world meaning (a post
is always written by somebody), mirroring the existing precedent in `ProductCategoryFactory`,
which reuses an existing default `StoreLanguage` row and only creates one when none exists.

## Gherkin
```gherkin
Feature: Generate demo data from the command line

  Scenario: A developer generates demo data in the local environment
    Given a developer working in the "local" environment
    When the developer runs "php artisan demo:generate-data"
    Then the command finishes successfully
    And 10 customers, at least 10 orders, 10 products, 10 product categories, 10 blog posts, 10 blog categories and 10 blog tags have been added

  Scenario: Every product belongs to one of the seeded product categories
    Given a developer who has run the demo data seeder on an empty database
    When the developer inspects the seeded products
    Then every product belongs to one of the 10 seeded product categories
    And every seeded product category has exactly one product
    And no product category exists beyond the 10 seeded ones

  Scenario: Every seeded customer has one or more orders, built from seeded products
    Given a developer who has run the demo data seeder on an empty database
    When the developer inspects the seeded customers and orders
    Then every one of the 10 seeded customers has between 1 and 3 orders
    And every order has between 1 and 4 items
    And every order item references one of the 10 seeded products
    And every order's subtotal and total are greater than zero and match its items
    And no customer or product exists beyond the 10 seeded ones

  Scenario: Blog posts use the seeded blog categories, an existing author, and a shared tag pool
    Given a developer who has run the demo data seeder on an empty database
    When the developer inspects the seeded blog posts
    Then every blog post is published and belongs to one of the 10 seeded blog categories
    And every blog post's author is a real, already-existing user
    And the first seeded blog post has tags attached from the 10 seeded blog tags
    And no blog tag exists beyond the 10 seeded ones

  Scenario: Seeding falls back to one new user only when the database has none
    Given a developer who has run the demo data seeder on a database with no existing users
    When the developer inspects the seeded blog posts' authors
    Then exactly one new user was created to author them
    And every blog post's author is that same fallback user

  Scenario: Seeded blog posts get a slug derived from their title
    Given a developer who has run the demo data seeder on an empty database
    When the developer inspects the seeded blog posts
    Then every blog post has a non-empty slug derived from its title

  Scenario Outline: A store operator is refused outside the allow-listed environments
    Given a store operator working in the "<environment>" environment
    When the store operator runs "php artisan demo:generate-data" without "--force"
    Then the command exits with a failure status
    And it prints a message explaining that demo data is only generated in local/testing unless --force is passed
    And no customer, order, product, product category, blog post, blog category or blog tag has been added

    Examples:
      | environment |
      | production  |
      | staging     |

  Scenario: A store operator forces demo data into a non-allow-listed environment
    Given a store operator working in the "staging" environment
    When the store operator runs "php artisan demo:generate-data --force"
    Then the command finishes successfully
    And 10 customers, at least 10 orders, 10 products, 10 product categories, 10 blog posts, 10 blog categories and 10 blog tags have been added

  Scenario: Running the command twice adds a second batch
    Given a developer who has already run "php artisan demo:generate-data" once in the "local" environment
    When the developer runs "php artisan demo:generate-data" again
    Then 20 customers, 20 products, 20 product categories, 20 blog posts, 20 blog categories and 20 blog tags exist
    And at least 20 orders exist, with every one of the 20 customers still having one or more orders
```

## Files to create/modify
- `database/seeders/DemoDataSeeder.php` — new. All data-generation logic, factories only (no
  domain Actions). Must **not** use `WithoutModelEvents` (unlike `DatabaseSeeder`):
  `BlogPost::booted()` has a `saving()` listener that derives `slug` from `title`; suppressing
  model events would silently create posts with empty/invalid slugs. Seeding happens in an
  explicit dependency order, each step building only on pools already created by an earlier step
  — nothing ever references a row that does not exist yet, so the run cannot fail on a missing
  foreign key:
  1. **Creator user pool:** reuse every existing `User` row in the database; if none exist, create
     exactly one fallback user via `User::factory()->create()`. Needed before blog posts (step 8),
     resolved first since it depends on nothing.
  2. **Product categories (10):** `ProductCategory::factory()->count(10)->create()`.
  3. **Products (10):** one per category, round-robin so every category gets exactly one product
     (a random pick risks leaving categories empty at this pool size); `->active()` state so they
     are browsable, not stuck in Draft. Depends on step 2.
  4. **Customers (10):** `Customer::factory()->count(10)->create()`.
  5. **Orders (≥10, driven off the customer pool):** for **each** of the 10 customers, create
     `fake()->numberBetween(1, 3)` orders for that customer — guaranteeing no customer is left
     without an order (total order count therefore varies, ~20 on average, but is always ≥10).
     Do **not** use `OrderFactory::withItems()` as-is (it nests fresh `Product::factory()` calls
     per item, ballooning the catalog). Instead, per order create 1–4 `OrderItem` rows with an
     explicit `product_id` from the seeded product pool (step 3), then recompute the order's
     `subtotal`/`total` from the real item totals (the same recompute `withItems()` does). Depends
     on steps 3 and 4.
  6. **Blog categories (10):** `BlogCategory::factory()->count(10)->create()`.
  7. **Blog tags (10):** created once up front as a shared pool, never per post (do **not** use
     `BlogPostFactory::withTags()` as-is — it always mints new tags).
  8. **Blog posts (10):** one blog category per post, round-robin; `->published()` state;
     `created_by` set to a randomly picked user from the pool resolved in step 1 (via
     `BlogPostFactory::createdBy($user)`). The **first** seeded post is always tagged
     (deterministic, so at least one tagged post is guaranteed rather than probabilistic); every
     other post has a ~70% chance of being tagged. When tagged, 1–3 distinct random existing tag
     ids from the pool (step 7) are attached via `$post->tags()->attach($ids)`. Depends on steps
     1, 6 and 7.
- `app/Console/Commands/GenerateDemoData.php` — new, thin wrapper. Gates the environment, then
  calls the seeder:
  ```php
  #[Signature('demo:generate-data {--force : Allow running outside the local/testing environment allow-list}')]
  #[Description('Generate demo data (customers, orders, products, categories, blog content) for a local or demo environment')]
  ```
  Follows the existing `area:verb-phrase` kebab-case convention (`blog:publish-scheduled-posts`).
  Guard: if not `app()->environment(['local', 'testing'])` and `--force` is absent, print a clear
  refusal and return a non-zero exit code with no writes. Otherwise invoke
  `$this->call('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])` (the inner
  `--force` only suppresses `db:seed`'s own interactive confirmation, redundant once the outer gate
  has run).
- `tests/Feature/Seeders/DemoDataSeederTest.php` — new (sits beside the existing seeder tests,
  e.g. `DatabaseSeederTest.php`).
- `tests/Feature/Console/Commands/GenerateDemoDataTest.php` — new, mirrors the
  `tests/Feature/Console/Commands/PublishScheduledBlogPostsTest.php` precedent.

## Tests to perform

Main risk (QA): every relevant factory defaults its parent relation to a nested
`Model::factory()` (`OrderFactory`'s `customer_id`, `ProductFactory`'s `product_category_id`,
`BlogPostFactory`'s `blog_category_id`), so a naive loop silently fans out extra rows. Tests
therefore assert the pools stay at their exact intended size, not just "≥10 rows exist". Faker's
own output shape (email format, sentence structure) is **not** tested — the factories own that.

Seeder — `tests/Feature/Seeders/DemoDataSeederTest.php` (`RefreshDatabase`):
- [ ] Integration test: running the seeder creates exactly 10 rows in each of `customers`,
      `product_categories`, `products`, `blog_categories`, `blog_tags`, `blog_posts`, and at least
      10 rows in `orders`.
- [ ] Integration test: every product's `product_category_id` resolves into the category pool,
      `ProductCategory::count()` stays exactly 10, and every category has exactly one product
      (round-robin spread).
- [ ] Integration test: **every one of the 10 seeded customers has between 1 and 3 orders** — no
      customer has zero orders (`Customer::doesntHave('orders')` is empty).
- [ ] Integration test: every order has 1–4 items and non-zero `subtotal`/`total` equal to the sum
      of its items.
- [ ] Integration test: every order's `customer_id` resolves into the customer pool and
      `Customer::count()` stays exactly 10.
- [ ] Integration test: every order item's `product_id` references one of the 10 seeded products
      (no phantom one-off product; `Product::count()` stays 10).
- [ ] Integration test: every blog post's `blog_category_id` resolves into the blog-category pool
      and every post is published.
- [ ] Integration test: every blog post's `created_by` references a real, existing `User` row
      (never `null`).
- [ ] Integration test: when the database already has users before the seeder runs, no additional
      user is created — the seeder reuses the existing pool (`User::count()` unchanged).
- [ ] Integration test: when the database has **no** users before the seeder runs, exactly one
      fallback user is created and every seeded blog post is authored by that same user.
- [ ] Integration test: `BlogTag::count()` stays exactly 10 after the run.
- [ ] Integration test: the first seeded blog post always has 1–3 tags attached from the shared
      pool (deterministic, asserted via the pivot, not a fresh count).
- [ ] Integration test: no blog post has the same tag twice — no duplicate
      `(blog_post_id, blog_tag_id)` pivot rows.
- [ ] Integration test: every seeded product has `status` `ProductStatus::Active`.
- [ ] Regression test: every seeded blog post has a non-empty slug (guards against a future
      `WithoutModelEvents` being added to the seeder).

Command — `tests/Feature/Console/Commands/GenerateDemoDataTest.php`:
- [ ] Integration test: `demo:generate-data` is registered (e.g. present in `Artisan::all()`) with
      the description stated under "Files to create/modify".
- [ ] Integration test: happy path in `testing` — exits `0` and the database gained the expected
      rows (one coarse count assertion; the seeder test owns the detail).
- [ ] Negative test (dataset `production`, `staging`): without `--force` the command exits
      non-zero, prints a clear refusal message, and makes **zero** writes to any of the seven
      tables (snapshot counts before/after, assert unchanged). Testing `staging` as well as
      `production` catches a blocklist-shaped bug such as `if (app()->environment('production'))`.
- [ ] Integration test: `--force` in a non-allow-listed environment runs anyway, with the same
      happy-path assertions.
- [ ] Edge case test: running the command twice in `testing` leaves exactly 20 customers, 20
      products, 20 product categories, 20 blog posts, 20 blog categories and 20 blog tags, at
      least 20 orders, and every one of the 20 customers still has one or more orders (documents
      D-2's additive behaviour together with D-4's per-customer order guarantee). Both runs happen
      in one PHP process, where `fake()->unique()` state is shared, so this test is **not** a
      guarantee against Faker-uniqueness collisions across separate command invocations (see
      Dependencies/risks).

## Expected outcome
`php artisan demo:generate-data` in a `local`/`testing` environment resolves an author pool (every
existing user, or one freshly-created fallback user if none exist), then adds exactly 10 product
categories, 10 active products (one per category), 10 customers, at least 10 orders — every
customer getting 1 to 3 of them, each order with 1–4 items drawn from the seeded catalog and
correct totals — 10 blog categories, 10 blog tags and 10 published blog posts (one per blog
category, each authored by an existing user, most of them tagged from the shared tag pool), all
with Faker-generated realistic values. Every row is created only after the rows it depends on, so
the run cannot fail on a missing relationship. Elsewhere it refuses with a clear message and writes
nothing, unless `--force` is passed.

## Acceptance criteria
- [ ] `demo:generate-data` is registered and listed by `php artisan list` with the description above.
- [ ] In `local`/`testing` it exits `0` and adds exactly 10 each of customers, products, product
      categories, blog posts, blog categories and blog tags, plus at least 10 orders.
- [ ] No extra product categories, customers, products or blog tags are created as a side effect of
      nested factory defaults.
- [ ] Every product belongs to a seeded category, every category has exactly one product, every
      product is active.
- [ ] Every one of the 10 seeded customers has between 1 and 3 orders — no customer is left without
      one.
- [ ] Every order has 1–4 items that reference seeded products, and has `subtotal`/`total`
      recomputed from its items (non-zero).
- [ ] Every blog post is published, belongs to a seeded blog category, has a non-empty slug, and is
      authored by a real, already-existing `User` (reused from the database, or the single
      fallback user created when none existed).
- [ ] The first seeded blog post is always tagged from the shared pool (deterministic); no post has
      the same tag twice.
- [ ] Outside `local`/`testing` without `--force`: non-zero exit, clear refusal message, zero writes.
- [ ] With `--force` in any environment: runs as in `local`.
- [ ] A second run adds another batch (additive, per D-2) and every customer old and new still has
      at least one order; a Faker-uniqueness collision across separate runs is a known, accepted
      limitation (see Dependencies/risks), not something this story's tests assert against.
- [ ] `DemoDataSeeder` does not use `WithoutModelEvents`; the command holds the environment guard,
      the seeder holds no guard of its own.

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper)
- [ ] Acceptance criteria met
- [ ] Pint run unscoped (`vendor/bin/pint --format agent`), Larastan level 7 clean
- [ ] Full test suite green, unscoped (`php artisan test`)

## Dependencies, risks, open technical questions
- **Dependencies:** none on other open stories. Relies on the existing factories and their
  `ProductFactory::active()` / `BlogPostFactory::published()` states.
- **Known limitation — additive runs (D-2):** re-running the command keeps adding batches; there is
  no reset. A future "reset demo data" feature would need schema support (see D-3).
- **Known, accepted limitation — cross-run Faker uniqueness (D-2):** several Faker-generated
  columns sit under real unique DB indexes (`blog_tags.normalized_name`,
  `product_category_translations (store_language_id, name)`, `customers.email`, blog post `slug`).
  `fake()->unique()` only guards one PHP process, so a separate `php artisan demo:generate-data`
  invocation can theoretically collide with a row an earlier run created. This is accepted, not
  defended against (no retry/uniqueness-recovery logic), matching `BlogTagFactory`'s own R-5
  docblock note ("Faker's unique() only guards one Faker instance, never the database"). The
  "running twice" test exercises one process only and does not cover this case.
- **Expected side rows outside the seven named tables:** on an empty database the reused factories
  also write one `payment_methods` row (`OrderFactory`'s existing fallback when none exists), one
  default `store_languages` row and 10 `product_category_translations` rows
  (`ProductCategoryFactory`'s existing translation-writing behavior). Tests must not assert "no
  extra rows" against those tables.
- **Known limitation — no demo marker (D-3):** demo rows cannot be told apart from real rows.
  `--force` against an environment holding real data mixes the two irreversibly; the refusal message
  should make that consequence clear.
- **Risk — nested factory fan-out:** mitigated by the "build the pool first, wire foreign keys
  explicitly" approach and by the exact-count tests above.
- **Risk — silent empty slugs:** mitigated by not using `WithoutModelEvents` and by the slug
  regression test.
- **Risk — order-less customers:** mitigated by driving order creation off the customer pool
  (step 5 of the seeding order) instead of picking a random customer per order, which could
  statistically skip a customer at only 10 orders for 10 customers.
- **Risk — no user to author a blog post:** mitigated by resolving the creator pool first (step 1)
  and falling back to exactly one freshly-created user when the database has none — the seeder can
  never reach the blog-post step without a valid `created_by` candidate.
- **Minor scope addition (post-review, D-5):** the fallback-user creation is new since the initial
  Phase 1 draft — the project owner asked that blog posts belong to an existing user. It is scoped
  to the minimum needed (reuse if any user exists, otherwise exactly one), not a bulk user seed, so
  it does not conflict with "Out of scope"'s original stance on not bulk-seeding users.
- **Testability note (QA):** the command tests must force the application environment (e.g.
  override `$this->app['env']` before `$this->artisan(...)`). There is **no existing precedent** for
  this pattern in the suite — `DatabaseSeeder`'s own allow-list has never been exercised by a test.
  The implementer should confirm the chosen hook actually changes what `app()->environment()`
  returns inside the command, and restore it so no other test is affected.
- **Open technical questions:** none blocking.

## Out of scope
- A `--count` option or configurable volumes.
- Truncating/resetting previously generated demo data; an `is_demo` flag or any schema change.
- Bulk-seeding users, roles, taxes, shipping or any entity not named in the request. The single
  fallback user created when none exist (D-5) is the sole, minimal exception, needed only because
  `blog_posts.created_by` must reference a real user.
- Any UI (admin button) to trigger demo data generation.
