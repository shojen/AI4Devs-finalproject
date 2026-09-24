# Derived columns: every invariant must hold at every write site

Established by story **0029**'s Phase 4 audit (product variants — the derived `product_variants.sku`).
This is the first page here about a **stored derived column** — a value the application computes from
other rows and then persists, rather than one a user supplies.

> 🟢 **Status: both sections below are ✅ CLOSED as of 2026-09-04** (closed by the same day's
> remediation and verified by this page's own author at the Phase 4 re-audit — the slot the ❌/✅
> framing was written to leave open, per
> [errors-log.md](../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)'s
> audit-authored-page rule). Every ❌ block below is kept verbatim as the record of what shipped from
> Phase 3 — each claim about it was **reproduced by execution** against this worktree's MySQL 8.4
> instance, not reasoned about — and is now followed by the shipped ✅ rather than being deleted.
>
> ⚠️ **The remediation introduced one new finding of its own**, recorded in
> [What the remediation introduced](derived-column-invariants/retry-and-transaction-hazards.md#what-the-remediation-introduced-a-retried-transaction-is-a-retry-safe-unit-or-it-is-a-lost-update)
> below. **Corrected 2026-09-04 (second re-audit, same day): that section is now ✅ CLOSED too** — it
> read *"That section is ❌ **OPEN** as of 2026-09-04"*, which stopped being true the moment
> `UpdateProduct` dropped its `attempts: 3`. The sentence is kept here rather than deleted, per the
> same audit-authored-page rule the paragraph above cites: a page written *during* an audit documents
> a state the remediation is expected to change, so its own claims go stale first.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [The rule and what closes it](derived-column-invariants/rule-and-remedy.md) | you add or change a write site for a derived column (hash, SKU, totals) and need the rule, the failure mode and the closing pattern. | The rule; Why a derived column concentrates this failure; ❌ The three guards that exist on one write site and not the o...; ✅ What closes it; The review question |
| [Relation re-reads and retried-transaction hazards](derived-column-invariants/retry-and-transaction-hazards.md) | you add `attempts:` to `DB::transaction()`, nest transactions, or rely on a reloaded relation or `causedByConcurrencyError()`. | Related: re-loading a relation does not re-read the key it re...; What the remediation introduced: a retried transaction is a r...; Second confirming instance: a generator's own outer transaction; Confirmed safe: `causedByConcurrencyError()` matches a messag... |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
