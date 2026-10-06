# Directory Structure

Part of [Base Standards](base-standards.md) — see [base-standards.md](base-standards/stack-and-model-conventions.md#stack-versions) for stack versions and the rest of this project's baseline conventions. This file covers the real `app/`/`routes/`/`database/`/`resources/`/`tests/` directory layout — what goes where, and why — plus the three directory-layout-adjacent conventions it enforces: config-file-as-registry, controllers-in-front-of-actions, and authorization-rule-belongs-to-the-action.

## Directory structure

Real top-level layout — stick to it; don't create new base folders without approval (per project `CLAUDE.md`):

Compact map (one line per folder; the full per-folder narrative lives in the part named on the right):

```
app/
  Actions/        domain actions, one subfolder per area (+ Auth/ cross-cutting)        -> directory-structure/actions.md
  Concerns/       shared traits (validation rule sets)                                   -> directory-structure/app-layers.md
  Console/        Artisan commands                                                       -> directory-structure/app-layers.md
  Enums/          backed enums for domain value sets                                     -> directory-structure/app-layers.md
  Exceptions/     domain exceptions that render their own response                       -> directory-structure/app-layers.md
  Http/           abstract base + domain controllers; Http/Middleware/ for web-group middleware -> directory-structure/app-layers.md
  Events/         domain events dispatched by actions                                    -> directory-structure/app-layers.md
  Listeners/      event listeners (auto-discovered)                                      -> directory-structure/app-layers.md
  Livewire/       components grouped by area; Components/ holds reusable ones            -> directory-structure/livewire-models-policies.md
  Models/         Eloquent models                                                        -> directory-structure/livewire-models-policies.md
  Notifications/  notification classes                                                   -> directory-structure/livewire-models-policies.md
  Policies/       <Model>Policy, auto-discovered by name                                 -> directory-structure/livewire-models-policies.md
  Providers/      service providers                                                      -> directory-structure/livewire-models-policies.md
  Rules/          custom validation rules (stock Laravel location)                       -> directory-structure/livewire-models-policies.md
config/           Laravel + package config, plus the app-owned modules.php registry      -> directory-structure/config-database-resources-tests.md
database/         data/ (bundled fixtures), factories/, migrations/, seeders/            -> directory-structure/config-database-resources-tests.md
lang/             en/ and es/, key-for-key identical domain files                        -> directory-structure/config-database-resources-tests.md
resources/        views/ (anonymous Blade components, layouts, Livewire views)           -> directory-structure/config-database-resources-tests.md
routes/           web.php + one file per functional area                                 -> directory-structure/config-database-resources-tests.md
tests/            Feature/, Unit/, Browser/, Support/, Fixtures/                         -> directory-structure/config-database-resources-tests.md
```

The rules behind this layout (stock vs. new base folders, registration-free policies, `database/data/`, the routes-per-area convention, when a config file is a registry, controllers in front of actions, and where an authorization rule lives) are in the parts below.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [app/Actions/ — domain actions per area](directory-structure/actions.md) | you add or place an action class: which `app/Actions/<Area>/` folder it belongs to, and what each area's actions authorize. | `app/Actions/` |
| [Concerns, Enums, Exceptions, Events, Listeners](directory-structure/app-layers.md) | you add a validation trait, backed enum, domain exception, event or listener and need to know where it goes. | `app/Concerns/`, `Enums/`, `Exceptions/`, `Events/` and `List... |
| [Livewire, Models, Notifications, Policies, Providers, Rules](directory-structure/livewire-models-policies.md) | you add a Livewire component, model, notification, policy, provider or custom rule and need to know where it goes. | `app/Livewire/`, `Models/`, `Notifications/`, `Policies/`, `P... |
| [config, database, lang, resources, routes, tests](directory-structure/config-database-resources-tests.md) | you add a config file, seeder/factory/fixture, lang file, Blade view/component, route file or test and need to know where it goes. | `config/`, `database/`, `lang/`, `resources/`, `routes/` and... |
| [Reading the layout: stock locations and grouping rules](directory-structure/layout-conventions.md) | you must decide whether a new folder needs approval, how `app/Actions/` is grouped, or how routes are split per area. | Reading the layout |
| [An app-owned config file is a registry](directory-structure/config-registry.md) | you edit `config/modules.php` or `config/html-sanitizer.php`, or add any app-owned config file (no closures, keys not copy). | An app-owned config file is a registry, and must survive `con... |
| [Controllers in front of actions; authorization belongs to the action](directory-structure/controllers-and-authorization-rule.md) | you add a controller, or decide where an authorization check for an operation must live. | Controllers sit in front of actions, not instead of them; An authorization rule belongs to the action, not to one of it... |

_Last updated: 2026-10-02 — Story 0067 (admin UI language switcher). [Concerns, Enums, Exceptions, Events, Listeners](directory-structure/app-layers.md)'s `app/Concerns/` entry gained `InteractsWithUiLocale`; `Livewire/Settings/` gained `Language` and `LanguageSwitcher`. Earlier: Story 0083 (Dashboard home overview UI). Added the `Livewire/Dashboard/` area, the `ChecksAbilitiesSafely` concern, the dashboard and badge Blade components and `tests/Support/Dashboard/` to the sub-pages. Earlier: Story 0082 (Dashboard home overview — backend). [app/Actions/](directory-structure/actions.md) gained `Actions/Dashboard/`, a cross-module read-side area, and its non-action collaborator `ResolveSalesBuckets` (authorizes nothing; only the two series actions may call it). Earlier: story 0081 (demo data seeder command). [Concerns, Enums, Exceptions, Events, Listeners](directory-structure/app-layers.md)'s `Console/Commands/` entry gained `GenerateDemoData` (`demo:generate-data`), the second command in that folder — a thin wrapper that gates the environment then delegates to `db:seed --class=DemoDataSeeder`, returning that call's exit code. Earlier: story 0070 gave [app/Actions/](directory-structure/actions.md) a third cross-cutting-concern folder, `Actions/Translations/` (`SetTranslation`, `CompareTranslatedNames`), two new `Actions/ProductCategories/` entries (`BackfillProductCategoryTranslations`, `TranslateProductCategoryNameUniqueViolation`), and gave `app/Concerns/` its first behavioural trait, `HasTranslations`, with its companion interface `Translatable`; story 0066 added an `Http/Middleware/` row beside the existing `Http/Controllers/` one (holding `EnsureSitePasswordIsProvided` and now also `SetUiLocale`); story 0065 added `app/Notifications/BlogPostPublished` and `app/Listeners/SendBlogPostPublishedNotification`; story 0068 added `Actions/Localization/` and `Actions/StoreLanguages/` and widened the `database/data/` admission test (R-11); story 0064b added `BlogPostPublishFailureStage`, `ScheduledBlogPostPublishFailed` and `NotifyScheduledBlogPostPublishFailed` — see each story's own doc pass for detail, not restated here per this file's single-footer-line convention._
