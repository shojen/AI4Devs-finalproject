# Contracts

Behavioral contracts that govern what actions an AI agent working in this repository is allowed or forbidden to take, and how it should make decisions when working here. This is distinct from [architecture/authorization.md](architecture/authorization.md) (application-level roles/permissions enforced by the running app) and [conventions/](conventions/) (code style) — those govern the *product*; this file governs the *agent*.

New contracts are added as a `###` section in the fitting part under `contracts/` (or a new part), with a binding-core summary added to the matching block below; nothing here is removed without the change being visible in this file's history.

## Rules

This document is split into parts. Each block below gives the **binding core** of the rules in that part — enough to comply in the common case. Open the full part when its *Read the full text when* line applies to your task, or when you need the exact protocol, rationale or an example. Every part carries the original text unchanged and every heading keeps its original anchor name.

### [Uncertainty, commit practice and destructive database commands](contracts/safety-rules.md)

**Read the full text when:** you are unsure what the user wants, you are about to commit, or you are about to run any `migrate:*`/`db:wipe` style command.

- **Uncertainty:** if the request has more than one reasonable interpretation or lacks required information, stop and ask concise clarifying questions; offer options and label your pick _(recommended)_. Never assume, invent missing information, or make an irreversible choice unconfirmed.
- **Commit practice:** committing is an ordinary step (no approval needed). Stage named files with `git add <files>` — never `git add -A` / `git add .`. Write a clear, specific message.
- **Destructive DB commands:** never run `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `migrate:reset`, `db:wipe` or similar against a real database (the local dev database counts) without explicit user authorization **each time**. The test runner (`php artisan test`, `vendor/bin/pest`, `vendor/bin/phpunit`) is always safe. Never rely on `--env=testing` on a direct `artisan` call to protect the real database.

### [Full test suite gate and parallel agent file ownership](contracts/testing-and-parallel-agents.md)

**Read the full text when:** you are about to close a task (move it to `done/`) or dispatch two or more agents in the same batch.

- **Full suite gate:** never close a task while any test in the full suite fails, whoever's test it is. Before moving a task to `done/`, run the complete suite unscoped (`php artisan test`) once, isolated, and see it 100% green. Verify a suspicious mass failure is real (rule out two processes sharing one database) before acting on it.
- **Parallel agents:** never dispatch agents in the same batch when their write sets — including transient verification edits — can intersect. Enumerate each write set; run sequentially or state the ownership boundary in both prompts. An unexplained edit is blocking: stop and report. Do not accept a verification run that overlapped a concurrent edit.

### [Token-efficient reading and doc growth management](contracts/token-and-doc-rules.md)

**Read the full text when:** you dispatch subagents, decide what docs to read, or edit/sync any file under `docs/`.

- **Reading:** read `docs/README.md` first and open only the doc (or split *part*) whose row matches your task; scope reads to an exact heading/part; when dispatching another agent, name the exact `file.md#heading`, never a bare large file. A facilitator distills a brief once instead of each specialist re-reading; the Phase 1 task file is the brief for later phases; check the epic's decision digest before opening prior stories. Batch heavy dispatches in twos/threes. Diagnose a failed dispatch before retrying. Keep resumed-agent instructions terse.
- **Doc growth:** a doc keeps one `_Last updated: <date>, <what changed>_` line — no accumulating `_Previously:` chains. Write current state, not a chronological log. Split a section that outgrows its file (long docs are now a hub plus parts; a hub lists each part with a *Read when* line). Applies on every docs sync pass.

### [CI / GitHub Actions review protocol](contracts/ci-protocol.md)

**Read the full text when:** you diagnose a failing pipeline or review CI.

- List recent runs for the current branch (`gh run list --branch $(git branch --show-current) --limit 5`), view only the failed step (`gh run view <id> --log-failed`), reproduce locally with this project's commands (`php artisan test --compact --filter=...`, `vendor/bin/pint --format agent`, `composer types:check`) before touching code, fix the real cause (the test only if the test is wrong), then push and re-run `/watch-ci after-push` until green.

### [Commit granularity and pull request closure](contracts/commits-and-pull-requests.md)

**Read the full text when:** you split commits, push a branch, open a pull request, or finish a task.

- **Commits:** one commit per layer — production code, tests, docs — never combined; Conventional Commits `type(scope): summary` (scope = feature area, never the story id); name the story in the body; order `feat`/`fix` → `test` → `docs`, with `docs(tasks)` last.
- **Pull request:** a finished task ships as a PR against the branch its worktree was created from — never a direct merge. Title `[{task number}] {task title}`; description has exactly three Spanish sections `## Qué cambia` / `## Porqué` / `## Qué impacto tiene` plus the attribution line. Run `/watch-ci after-push` after pushing and after opening the PR; fix a red pipeline before handing over.
- **Never** merge, approve or close a PR (`gh pr merge`, `gh pr review --approve`, `gh pr close`) — even if asked to "merge it"; that is reserved for the project owner, with no exception.

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no rule changed. The prior revision-history footer, if any, stays at the end of the last part._
