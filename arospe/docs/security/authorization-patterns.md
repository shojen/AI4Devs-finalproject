# Authorization Patterns

Rules for working with the `spatie/laravel-permission` authorization foundation established by task
0002. Every rule below was derived from reading the installed vendor source (`spatie/laravel-permission`
8.3.0, `laravel/framework` 13) rather than from documentation, because the package's own docs do not
make these distinctions explicit.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Super Admin bypass, permission cache and guard arguments](authorization-patterns/bypass-cache-and-guards.md) | you touch `Gate::before`, permission-cache flushing, `hasRole()` guards, the Super Admin role-name default, or `permission:`/`role:` middleware. | The Super Admin bypass does not cover every check; Flush the permission cache after the transaction commits, nev...; Always pass the guard to hasRole() / hasAnyRole(); Read the Super Admin role name with a literal default; Gate::before closures must tolerate any authenticatable; permission: and role: middleware are not a substitute for auth |
| [Ability coverage, protected identity and relation reloads](authorization-patterns/ability-coverage-and-guards.md) | you write or review an ability's coverage, a guard reading a row's protected identity, a Super-Admin-binding rule, a relation reload before a check, or a full-set sync behind a partial form. | An ability must cover every attribute that achieves its effec...; A guard that reads a row's protected identity must distinguis...; A rule that must bind a Super Admin actor must be a direct th...; Authorization that consults a relation must reload it before...; A full-set sync behind a partially-visible form must preserve... |
| [Payload omission, registries and rate limits](authorization-patterns/payload-omission-and-registries.md) | you handle identity derived from a mutable column, what an omission means across guards, DOM-omitted controls, submitted-list checks, an ungated-by-absence registry, or a target-keyed rate limit. | An identity derived from a mutable column must be locked once...; Two guards on one payload must agree on what an omission means; A control omitted from the DOM is safe only for the one value...; A check over a submitted list must accept every shape the wri...; A registry that means "ungated" by *absence* fails open, sile...; A rate limit keyed on the target alone becomes an attack on t... |
| [Confirmed-safe patterns](authorization-patterns/confirmed-safe.md) | you want the audited-and-confirmed-safe cases: sidebar `Gate::any()`, `can:` 403 wording vs `APP_DEBUG`, role-name collision guards. | Confirmed safe: a sidebar built on `Gate::any()` inherits the...; Confirmed safe: a `can:`-gated route's 403 names no permission; Confirmed safe: role-name collision is closed by a creation/r... |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
