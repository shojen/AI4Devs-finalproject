# Bounding an array of ids at the validation boundary

Established by story 0026's Phase 4 **re-audit** (finding R-1), while verifying that story's own
first-round fix for finding F-3. The rule it establishes is not about sales regions — it is about
every `'field' => ['array', 'max:N']` + `'field.*' => [..., Rule::exists(...)]` pair in this
codebase, of which there are two today and will be more with every list-shaped form Epic 2 and
Epic 3 add.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [The rule, call sites and shapes that bound it](array-validation-bounds/rule-and-shapes.md) | you validate a submitted array of ids: why `max:` does not gate `.*`, and the two-pass shape. | A `max:` rule on an array does not gate that array's `.*` rules; No array-level rule gates them; The call sites in this repo today; Confirmed: neither `bail` form helps; The shapes that do bound it |
| [Per-story status and re-audits](array-validation-bounds/story-history.md) | you need how the rule was applied/closed per story (0026, 0027, 0028, 0031a), including the mutation-point and loop-work caps. | Status in story 0026: bounded by a written hand-off, not by code; Story 0027: the two-pass shape is only half the bound; Story 0027 re-audit: a cap on the array's *length* is not a c...; Status in story 0028: the rule's first real, shipped, closed...; Story 0031a: the mutation point rule extends to a `#[Computed... |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
