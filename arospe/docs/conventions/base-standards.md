# Base Standards

Baseline stack versions and project-structure standards for this Laravel + Livewire application. This is the "what shape does new code take" reference; for line-level style (types, braces, PHPDoc) see [code-style.md](code-style.md), and for identifier naming see [naming.md](naming.md).

## Parts

This document is split into parts. Each block below gives the **binding core** of the rules in that part — enough to comply in the common case. Open the full part when its *Read the full text when* line applies to your task, or when you need the exact protocol, rationale or an example. Every part carries the original text unchanged and every heading keeps its original anchor name.

### [Stack versions, model and UUID conventions](base-standards/stack-and-model-conventions.md)

**Read the full text when:** you add or change a model, a mass-assignment list, a delete path, a primary key, or need exact stack versions.

- Stack: PHP ^8.3, Laravel ^13.17, Livewire ^4.1, Flux ^2.13, spatie/laravel-permission ^8.3, Pest ^4.7, Larastan ^3.9, Pint ^1.27; Tailwind v4 + Vite.
- Models use attribute-based `#[Fillable]`/`#[Hidden]` and a `casts()` method. Mass-assignment guard: leave a column out of `#[Fillable]` and write it with `forceFill()`/`forceCreate()` from one named place.
- Delete a user (or any model with a `delete()` override) through the instance — never a query-builder bulk delete.
- New domain models use UUIDv7 primary keys via `HasUuids` (`@property string $id`; do not declare `$keyType`/`$incrementing`).

### [Livewire, wire:ignore and Flux conventions](base-standards/livewire-and-flux-conventions.md)

**Read the full text when:** you write a Livewire component, a `contenteditable`/browser-owned region, or a popover/dropdown.

- Livewire components are class-based (not single-file): a `Livewire\Component` in `app/Livewire/**` with a kebab-case view mirror and a `#[Title]` attribute.
- Use `wire:ignore` only for a region a browser API owns (e.g. `contenteditable`); `flux:dropdown` needs a real `<button>` trigger, otherwise use the manual `x-show`/`x-cloak` popover fallback.

### [Artisan-first workflow and quality gates](base-standards/workflow-and-quality-gates.md)

**Read the full text when:** you finish any PHP change and must run the quality gates, or scaffold files.

- Scaffold with `php artisan make:*` and `--no-interaction`.
- Gates: `php artisan test --compact --filter=<Name>`, `vendor/bin/pint --dirty --format agent`, Larastan level 7. Those are the **iteration** forms — before declaring work done run Pint **unscoped** (`vendor/bin/pint --format agent`) and the **full** test suite unscoped (`php artisan test`, or `--parallel`).

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no rule changed. The prior revision-history footer, if any, stays at the end of the last part._
