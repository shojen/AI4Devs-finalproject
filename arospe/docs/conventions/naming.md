# Naming Conventions

Real naming patterns observed across the codebase. For migration file naming specifically, see [database/migrations.md](../database/migrations/basics-and-alterations.md#file-naming) — not repeated here.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Class and file naming](naming/classes.md) | you name a new class, action, controller, listener, notification, enum, policy or exception. | Classes |
| [Livewire components and views](naming/livewire-components-and-views.md) | you add a Livewire component and must place its view (the `Index`-in-a-subfolder exception). | Livewire components and views |
| [Route and permission names](naming/routes-and-permissions.md) | you name a route or a permission, or need the validation-trait pointer. | Traits and their methods; Route names; Permission names |
| [Translation keys and boolean properties](naming/translation-keys-and-booleans.md) | you add a lang key (including `trans_choice()` plurals and registry-mirroring files) or name a boolean property/predicate. | Translation keys; Boolean properties |

_Last updated: 2026-09-26 — `classes.md` notes that listeners are auto-discovered (story 0064a); the parts above are otherwise as split on 2026-09-24. The prior revision-history footer, if any, stays at the end of the last part._
