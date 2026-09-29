# Livewire Component Authorization

Rules governing how a Livewire component is authorized in this repo, established while
auditing task 0004 (`App\Livewire\Users\Index`, the first permission-gated screen). Everything here
was verified against the installed `livewire/livewire` v4 source, not inferred from the docs.

**Since story 0020 this page covers two shapes, not one.** Everything through task 0018 was about a
**full-page** component sitting behind its own route; `App\Livewire\Media\Gallery` is the first
**embedded child** with no route of its own, and the difference is not cosmetic — it is which of
these rules still has a backstop behind it. Read
[the routeless case](livewire-authorization/entry-point-and-method-gates.md#the-routeless-case-a-component-with-no-route-has-no-per-request-backstop-at-all)
before applying anything here to an embedded component.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [The /livewire/update entry point and per-method gates](livewire-authorization/entry-point-and-method-gates.md) | you write a Livewire action/opener that mutates or discloses data and must decide where its `Gate` check goes. | `/livewire/update` is a second entry point, and only an allow...; Gate at the top of every method that mutates or discloses |
| [#[Locked] properties and gate/display twins](livewire-authorization/locked-properties.md) | you add a public property to a Livewire component (which must be `#[Locked]`), use `Rule::unique()->ignore()`, or pair a save-time gate with its display hint. | `#[Locked]` is what makes `Rule::unique()->ignore()` safe here; Every server-derived property is `#[Locked]`, not just the ids; A save-time gate and its display-only twin must share one res... |
| [Authorization in the action, not only the component](livewire-authorization/action-level-authorization.md) | you extract or call an action and must ensure the rule binds every other caller (jobs, Artisan, tests). | Authorization that lives only in the component is bypassed by... |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
