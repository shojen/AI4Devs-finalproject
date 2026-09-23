# Revision history — `docs/security/derived-column-invariants/retry-and-transaction-hazards.md`

> Moved here **unchanged** from the end of [`retry-and-transaction-hazards.md`](../security/derived-column-invariants/retry-and-transaction-hazards.md) in the 2026-09-24 docs optimization pass (a doc keeps one `_Last updated_` line; the accumulated `_Previously:` chain lives here). Read it only to trace when or why that document changed.

_Previously: 2026-09-04 (second re-audit, same day) — **this page's own remaining ❌ is now ✅**, and
the correction is the entry: the status banner and the
[What the remediation introduced](../security/derived-column-invariants/retry-and-transaction-hazards.md#what-the-remediation-introduced-a-retried-transaction-is-a-retry-safe-unit-or-it-is-a-lost-update)
section both still said ❌ OPEN after `UpdateProduct` had already dropped its `attempts: 3`, which is
[the audit-authored-page failure mode](../errors-log/archive-2026-08-17-to-2026-08-21.md#a-security-page-documented-the-vulnerable-code-as-current-because-it-was-written-before-its-own-fix--2026-08-20)
recurring on the page that cites it. Closed here with the ❌ text kept verbatim: **R-1** (closed by
removal — no `attempts` on `UpdateProduct`'s transaction, none reintroduced by `Editor::save()`, both
retained `attempts: 3` re-verified retry-safe, pinned by a source assertion that proves it can fail),
**R-3** (`UpdateProduct` now calls the shared `TranslateProductVariantUniqueViolation` with an
`?string $overrideMessage`; re-checked that the override is a `trans()` key from the call site and
opens no path for an index name or SQL to reach an actor-facing message), and **R-4** (both cascades
exclude `array_keys($newSkus)`, with the internal-duplicate check confirmed to still run **first**, the
gap-lock and fixed-lock-order arguments confirmed unaffected by the added `whereNotIn`, and one honest
narrowing recorded: a true two-element swap still cannot succeed through sequential updates against an
immediately-enforced `UNIQUE` index — it now fails at the write, cleanly and fully rolled back, rather
than at the pre-check)._

_Previously: 2026-09-04 (re-audit, same day) — both original sections closed by the story's own
remediation and re-verified here by execution, per the audit-authored-page rule. Added
[What the remediation introduced](../security/derived-column-invariants/retry-and-transaction-hazards.md#what-the-remediation-introduced-a-retried-transaction-is-a-retry-safe-unit-or-it-is-a-lost-update)
(❌ OPEN: `attempts: 3` over a closure that mutates an Eloquent model created outside it, with the
`SetSalesRegionActive` key-passing shape as the ✅, plus the coupled fact that `attempts` on a nested
transaction is inert and `Products\Editor::save()` is where the outermost one lives) and
[Confirmed safe: `causedByConcurrencyError()` matches a message, not a class](../security/derived-column-invariants/retry-and-transaction-hazards.md#confirmed-safe-causedbyconcurrencyerror-matches-a-message-not-a-class).
Two ⚠️ notes added inside the existing sections: the two cascades' per-row pre-check excludes only its
own row rather than the whole batch (a fail-closed false refusal on a two-variant SKU rotation), and
`UpdateProduct`'s outer `1062` catch re-implements the two-index disambiguation inline instead of using
the extracted translator._

_Previously: 2026-09-04 — created by story 0029's Phase 4 audit (product variants — core backend).
Both sections ❌ OPEN. Every reproduction was executed against this worktree (MySQL 8.4,
`STRICT_TRANS_TABLES`, `REPEATABLE-READ`) using temporary test files that were removed afterwards._
