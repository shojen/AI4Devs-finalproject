# [0081] Demo data seeder command

## Description
A developer or store operator needs a single Artisan command that fills a local or demo
environment with realistic-looking data (via Faker): customers, orders, products, product
categories, blog posts, blog categories and blog tags — exactly 10 of each — with products
assigned to categories, orders built from the seeded catalog and customers, and some tags
attached to posts. The command refuses to run outside `local`/`testing` unless `--force` is given.

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
Each run adds another batch of exactly 10 of every entity on top of whatever already exists. No
truncation, no reset, no "demo data" marker.
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

### D-4 — Exactly 10 of every named entity; relationship cardinalities vary
Exactly 10 each of customers, orders, products, product categories, blog posts, blog categories and
blog tags (the request says "at least 10"; exactly 10 satisfies it and is deterministic and simple
to assert). Order items per order (1–4) and tags per post (0–3, roughly 70% of posts tagged) vary,
since those are realism details of relationships, not entities the request named a count for. No
`--count` option.

## Gherkin
```gherkin
Feature: Generate demo data from the command line

  Scenario: A developer generates demo data in the local environment
    Given a developer working in the "local" environment
    When the developer runs "php artisan demo:generate-data"
    Then the command finishes successfully
    And 10 customers, 10 orders, 10 products, 10 product categories, 10 blog posts, 10 blog categories and 10 blog tags have been added

  Scenario: Every product belongs to one of the seeded product categories
    Given a developer who has run the demo data seeder on an empty database
    When the developer inspects the seeded products
    Then every product belongs to one of the 10 seeded product categories
    And every seeded product category has exactly one product
    And no product category exists beyond the 10 seeded ones

  Scenario: Every order is built from seeded customers and seeded products
    Given a developer who has run the demo data seeder on an empty database
    When the developer inspects the seeded orders
    Then every order belongs to one of the 10 seeded customers
    And every order has between 1 and 4 items
    And every order item references one of the 10 seeded products
    And every order's subtotal and total are greater than zero and match its items
    And no customer or product exists beyond the 10 seeded ones

  Scenario: Blog posts use the seeded blog categories and a shared tag pool
    Given a developer who has run the demo data seeder on an empty database
    When the developer inspects the seeded blog posts
    Then every blog post is published and belongs to one of the 10 seeded blog categories
    And at least one blog post has tags attached from the 10 seeded blog tags
    And no blog tag exists beyond the 10 seeded ones

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
    And 10 of each demo entity have been added

  Scenario: Running the command twice adds a second batch
    Given a developer who has already run "php artisan demo:generate-data" once in the "local" environment
    When the developer runs "php artisan demo:generate-data" again
    Then 20 of each demo entity exist
```

## Files to create/modify
- `database/seeders/DemoDataSeeder.php` — new. All data-generation logic, factories only (no
  domain Actions). Must **not** use `WithoutModelEvents` (unlike `DatabaseSeeder`):
  `BlogPost::booted()` has a `saving()` listener that derives `slug` from `title`; suppressing
  model events would silently create posts with empty/invalid slugs. Seeding order, each step
  building on the previous pool and wiring foreign keys explicitly:
  1. **Product categories (10):** `ProductCategory::factory()->count(10)->create()`.
  2. **Products (10):** one per category, round-robin so every category gets exactly one product
     (a random pick risks leaving categories empty at this pool size); `->active()` state so they
     are browsable, not stuck in Draft.
  3. **Customers (10):** `Customer::factory()->count(10)->create()`.
  4. **Orders (10):** customer picked randomly from the pool (not round-robin — realistic order
     history has repeat and zero-order customers). Do **not** use `OrderFactory::withItems()` as-is
     (it nests fresh `Product::factory()` calls per item, ballooning the catalog). Instead, per
     order create 1–4 `OrderItem` rows with an explicit `product_id` from the seeded product pool,
     then recompute the order's `subtotal`/`total` from the real item totals (the same recompute
     `withItems()` does).
  5. **Blog categories (10):** `BlogCategory::factory()->count(10)->create()`.
  6. **Blog tags (10):** created once up front as a shared pool, never per post (do **not** use
     `BlogPostFactory::withTags()` as-is — it always mints new tags).
  7. **Blog posts (10):** one blog category per post, round-robin; `->published()` state. Per
     post, a ~70% chance of being tagged; when tagged, 1–3 distinct random existing tag ids from
     the pool attached via `$post->tags()->attach($ids)`.
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
      `product_categories`, `products`, `orders`, `blog_categories`, `blog_tags`, `blog_posts`.
- [ ] Integration test: every product's `product_category_id` resolves into the category pool,
      `ProductCategory::count()` stays exactly 10, and every category has exactly one product
      (round-robin spread).
- [ ] Integration test: every order has 1–4 items and non-zero `subtotal`/`total` equal to the sum
      of its items.
- [ ] Integration test: every order's `customer_id` resolves into the customer pool and
      `Customer::count()` stays exactly 10.
- [ ] Integration test: every order item's `product_id` references one of the 10 seeded products
      (no phantom one-off product; `Product::count()` stays 10).
- [ ] Integration test: every blog post's `blog_category_id` resolves into the blog-category pool
      and every post is published.
- [ ] Integration test: `BlogTag::count()` stays exactly 10 after the run.
- [ ] Integration test: at least one blog post has ≥1 tag attached from the shared pool (asserted
      via the pivot, not a fresh count).
- [ ] Regression test: every seeded blog post has a non-empty slug (guards against a future
      `WithoutModelEvents` being added to the seeder).

Command — `tests/Feature/Console/Commands/GenerateDemoDataTest.php`:
- [ ] Integration test: happy path in `testing` — exits `0` and the database gained the expected
      rows (one coarse count assertion; the seeder test owns the detail).
- [ ] Negative test (dataset `production`, `staging`): without `--force` the command exits
      non-zero, prints a clear refusal message, and makes **zero** writes to any of the seven
      tables (snapshot counts before/after, assert unchanged). Testing `staging` as well as
      `production` catches a blocklist-shaped bug such as `if (app()->environment('production'))`.
- [ ] Integration test: `--force` in a non-allow-listed environment runs anyway, with the same
      happy-path assertions.
- [ ] Edge case test: running the command twice in `testing` leaves exactly 20 of each entity
      (documents D-2's additive behaviour).

## Expected outcome
`php artisan demo:generate-data` in a `local`/`testing` environment adds exactly 10 customers,
10 orders (each with 1–4 items drawn from the seeded catalog and correct totals), 10 active products
spread one per each of 10 product categories, 10 blog categories, 10 blog tags and 10 published blog
posts (one per blog category, most of them tagged from the shared tag pool), all with Faker-generated
realistic values. Elsewhere it refuses with a clear message and writes nothing, unless `--force` is
passed.

## Acceptance criteria
- [ ] `demo:generate-data` is registered and listed by `php artisan list` with the description above.
- [ ] In `local`/`testing` it exits `0` and adds exactly 10 of each of the seven named entities.
- [ ] No extra product categories, customers, products or blog tags are created as a side effect of
      nested factory defaults.
- [ ] Every product belongs to a seeded category, every category has exactly one product, every
      product is active.
- [ ] Every order belongs to a seeded customer, has 1–4 items that reference seeded products, and
      has `subtotal`/`total` recomputed from its items (non-zero).
- [ ] Every blog post is published, belongs to a seeded blog category, and has a non-empty slug.
- [ ] At least one blog post is tagged from the shared pool; no post has the same tag twice.
- [ ] Outside `local`/`testing` without `--force`: non-zero exit, clear refusal message, zero writes.
- [ ] With `--force` in any environment: runs as in `local`.
- [ ] A second run adds another batch (additive, per D-2) and does not fail.
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
- **Known limitation — no demo marker (D-3):** demo rows cannot be told apart from real rows.
  `--force` against an environment holding real data mixes the two irreversibly; the refusal message
  should make that consequence clear.
- **Risk — nested factory fan-out:** mitigated by the "build the pool first, wire foreign keys
  explicitly" approach and by the exact-count tests above.
- **Risk — silent empty slugs:** mitigated by not using `WithoutModelEvents` and by the slug
  regression test.
- **Testability note (QA):** the command tests must force the application environment (e.g.
  override `$this->app['env']` before `$this->artisan(...)`). There is **no existing precedent** for
  this pattern in the suite — `DatabaseSeeder`'s own allow-list has never been exercised by a test.
  The implementer should confirm the chosen hook actually changes what `app()->environment()`
  returns inside the command, and restore it so no other test is affected.
- **Open technical questions:** none blocking.

## Out of scope
- A `--count` option or configurable volumes.
- Truncating/resetting previously generated demo data; an `is_demo` flag or any schema change.
- Seeding users, roles, taxes, shipping or any entity not named in the request.
- Any UI (admin button) to trigger demo data generation.
