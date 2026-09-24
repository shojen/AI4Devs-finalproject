# Security Knowledge Base

Project-specific security knowledge for this Laravel 13 + Livewire 4 application, written by the
[`appsec-auditor`](../../.claude/agents/appsec-auditor.md) agent during Phase 4 of
[`docs/workflow.md`](../workflow.md).

Same "only lasting-value entries" spirit as [`errors-log.md`](../errors-log.md) and
[`decisions/`](../decisions/): per-review finding lists live in the audit response, not here. A page
is added here only when an audit establishes a **durable rule or pattern** that future code in this
repo must follow — always with a real code example pulled from this repository.

## Index

One row per page — open the page whose *Read when* matches your task. The long per-page abstracts and the revision history that used to sit here are kept unchanged in [index-details-and-history.md](index-details-and-history.md); you rarely need them.

| Page | Read when |
| --- | --- |
| [Authorization patterns](authorization-patterns.md) | you touch `Gate`/policies/roles/permissions, module gates or the sidebar registry: Super Admin bypass gaps, cache-flush timing, ability coverage, omission semantics, ungated-by-absence registries. |
| [Seeder safety](seeder-safety.md) | you write or change a seeder: which columns are seeder-owned vs administrator-configurable, and why upsert is the wrong default. |
| [Signed-link verification](signed-link-verification.md) | you add a signed route: `ValidateSignature` ordering before `SubstituteBindings` and address-bound, single-use links. |
| [Livewire component authorization](livewire-authorization.md) | you write a Livewire action/opener: `/livewire/update` as a second entry point, per-method gates, `#[Locked]` properties, action-level rules. |
| [Soft-delete security patterns](soft-delete-patterns.md) | you soft-delete a model or protect administrator-level accounts. |
| [CI workflow hardening](ci-workflow-hardening.md) | you edit GitHub Actions workflows. |
| [Blade / Livewire output encoding](blade-livewire-output-encoding.md) | you render user-supplied data or use `{!! !!}` in a view. |
| [Login-time account-status enforcement](login-status-enforcement.md) | you touch sign-in paths or `users.status` enforcement across the three authentication entry points. |
| [Model-instance trust](model-instance-trust.md) | an action receives a caller-supplied model instance: re-fetch under lock, `save()` vs `fill()` guard limits. |
| [Step-up authentication](step-up-authentication.md) | you gate a privileged action behind a recently confirmed password. |
| [Image upload & processing](image-upload-processing.md) | you accept or convert an uploaded image: validation, resource limits, byte-signature test design. |
| [HTML sanitization](html-sanitization.md) | you persist or render rich HTML (product description, blog body): the single allow-list config. |
| [Array validation bounds](array-validation-bounds.md) | you validate a submitted array of ids (`max:` vs `.*`, two-pass shape, mutation-point caps). |
| [Derived-column invariants](derived-column-invariants.md) | you write a derived column (hash, SKU, totals) or add `attempts:` to a transaction. |
| [Livewire error-bag persistence](livewire-error-bag-persistence.md) | you call `addError()` on a Livewire component or make a validation message persist. |
| [Resolving a related pair of ids](related-id-pair-resolution.md) | a payload names two ids that must belong together (a product and one of its variants). |

_Last updated: 2026-09-24 — Docs optimization pass: index compacted to one row per page; the detailed abstracts and the accumulated `_Previously:` history moved unchanged to [index-details-and-history.md](index-details-and-history.md). Add a new page as one row here._
