# Multi-Agent Development Orchestration — Agent roster, task storage and epic digests

> Part of [Multi-Agent Development Orchestration](../workflow.md). **Read this part when:** you need to know which agent does what, the `ai-spec/tasks/` → `in-progress/` → `done/` convention, or the per-epic decision digest. The other parts are listed in the [hub](../workflow.md#parts).

## Available agents and single responsibility

| Agent | Responsibility |
|---|---|
| `product-owner` | Analyzes the request, leads the Three Amigos debate, writes the User Story, moves the task through `./ai-spec/tasks/` → `in-progress/` → `done/` as it advances. |
| `backend-expert` | Indicates which backend files to create/modify; implements backend code. |
| `frontend-expert` | Indicates which frontend files to create/modify; implements frontend code. |
| `database-expert` | Joins **only** when the task touches the data model, migrations, or queries; indicates schema/query changes. |
| `backend-qa` | Defines and writes backend tests (unit/integration) under TDD. |
| `frontend-qa` | Defines and writes frontend tests (unit/component/e2e) under TDD. |
| `appsec-auditor` | Audits the security of the implemented code. |
| `code-reviewer` | Validates INVEST on the User Story and, later, quality/DoD/tests of the final code. |
| `docs-keeper` | Continuously documents: the workflow itself, decisions, lessons learned, and final changes; verifies link integrity in **both** directions on every stage move — the moved file's own outbound links, and inbound links to it from files that never moved (see below). |

> **Task-storage convention:** task files have three stages. Phase 1 writes the User Story to
> `./ai-spec/tasks/<id>-<slug>.md` (**new** — defined, not yet picked up for implementation).
> When `backend-expert`/`frontend-expert` starts Phase 3 implementation, the file moves to
> `./ai-spec/tasks/in-progress/<id>-<slug>.md` (**in-progress**). On Phase 7 closure it moves
> to `./ai-spec/tasks/done/<id>-<slug>.md` (**done**). This reconciles the workflow with
> `product-owner`'s existing task-lifecycle convention defined in
> `.claude/agents/product-owner.md`.

`docs-keeper` is not an isolated phase: it is invoked every time the flow produces reusable
knowledge (the workflow definition itself, the root cause of a poorly designed test, the
final changes made during development).

## Decision digest per epic

Every agent dispatched against a task independently re-reads large parts of `docs/`, with no
context shared between sibling calls — see [contracts.md](../contracts/token-and-doc-rules.md#token-efficient-reading-and-dispatch-rule)
and [workflow-token-efficiency.md](../workflow-token-efficiency.md) for why this is the dominant
token cost in this workflow, not a minor one. A **decision digest** is the concrete fix for the
single largest driver of that cost within one epic: a later story in the same epic re-reading
every already-closed sibling story in full to inherit an established shape, when a handful of
facts from that story are actually load-bearing.

- **Where it lives:** `./ai-spec/tasks/_digests/epic-<n>.md`, one file per PRD epic. Create the
  folder and the file the first time a second story in an epic needs one — a single-story epic
  needs no digest.
- **What it holds, and what it must not:** only the shapes and decisions a later story in the
  same epic must not re-derive — trait/class/method names and signatures already established,
  resolved cross-story questions, naming or schema decisions that set a precedent. A few hundred
  lines at most, in short bullets, never the full prose of a finalized story. It is a lookup
  table for facts, not a second copy of `docs/errors-log.md` or of the story files themselves —
  don't duplicate content that already has a durable home there.
- **Who writes it:** `docs-keeper`, appended (never rewritten wholesale) at Phase 6/7 of each
  story in the epic, immediately after the doc-sync pass for that story — the same moment it
  already has the story's real diff in hand.
- **Who reads it:** `product-owner` (directly, or via the `three-amigos-debate` skill) at Phase 0
  decomposition and Phase 1 debate for any story in that epic, **before** deciding whether it
  needs to open a prior sibling story file in full at all. Reading the digest first is what makes
  "read the full sibling story only when the digest doesn't already answer the question" possible
  instead of habitual.

A digest entry is short by construction: `- <fact/decision> — <which story it's from>`. If a
later reader needs more than the digest gives, that is the signal to open the cited story's
specific section — never a reason to pad the digest itself into a second story file.
