# Contracts — Uncertainty, commit practice and destructive database commands

> Part of [Contracts](../contracts.md). **Read this part when:** you are unsure what the user wants, you are about to commit, or you are about to run any `migrate:*`/`db:wipe` style command. The other parts are listed in the [hub](../contracts.md#rules).

### Uncertainty Handling Rule

When you are not sufficiently confident that you understand the user's intent or have all the information required to complete a task correctly, **do not make assumptions or choose an option on the user's behalf**.

Follow this protocol:

1. **Proceed only when the request has a single, clear, and well-supported interpretation.**
2. **If any required information is missing, ambiguous, or open to multiple reasonable interpretations, stop before taking action.**
3. **Ask concise, specific clarifying questions to resolve the ambiguity.**
4. **Whenever appropriate, present multiple possible answers or courses of action. Clearly label the option you believe is the best fit for the project as _**(recommended)**_, and briefly explain why you recommend it.**
5. **Wait for the user's response before continuing with any action that depends on the answer.**
6. **Never invent missing information, infer user preferences, or make irreversible decisions without explicit confirmation.**
7. **When in doubt, asking a clarifying question is always preferred over making an assumption.**

Your goal is to maximize correctness rather than speed. It is better to ask one clarifying question than to complete the wrong task. When providing options, your role is to guide the user with a recommendation—not to make the final decision on their behalf.

### Commit Practice Rule

Committing is an ordinary part of finishing a unit of work in this repository — the agent commits as part of completing a task, without stopping to ask for approval first. This rule governs *how* a commit is prepared, not whether one may run.

Follow this practice:

1. **Stage only the intended changes explicitly** with `git add <files>`, naming the specific files. Never stage with `git add -A`, `git add .`, or any other bulk/catch-all form.
2. **Write a clear, specific commit message** for the staged changes, describing what changed and why — see the Commit Granularity Rule below for how commits are split by layer and formatted in this repo.

The one git action that remains reserved for the project owner alone, with no exception, is approving, closing, or merging a pull request — see the Pull Request Closure Rule below, which has no approval override the way this rule's predecessor once did.

### Destructive Database Command Rule

Never run `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `migrate:reset`, `db:wipe`, or any other command that drops or rewinds schema/data against this project's **real** database without the user's **explicit authorization first**. "Real" means the local development database (`arospe`, per `.env`'s `DB_DATABASE`) exactly as much as any staging/production database — anything that is not the isolated `testing` database Laravel's test suite runs against.

Follow this protocol:

1. **Before running any destructive migration/wipe command directly** via `php artisan` (or `docker exec`/Sail), **stop and ask the user for explicit authorization.** Do not run it — not for debugging, not to "just reset state," and not because a similar destructive action was approved earlier in the same session. Approval does not carry over across commands or sessions; ask again each time.
2. **Running the test suite itself is exempt and always safe** — `php artisan test`, `vendor/bin/pest`, `vendor/bin/phpunit`, and Pest's `RefreshDatabase`/`DatabaseTransactions` traits may be run freely, because `phpunit.xml` pins `DB_DATABASE=testing` for every process launched through them, isolating all resets to the dedicated testing database.
3. **Do not trust a bare `--env=testing` flag (or similar) to redirect a direct `artisan` invocation to a safe database.** This repo has no `.env.testing` file, so `php artisan migrate:fresh --env=testing` run directly (outside the test runner) silently falls back to `.env`'s real `DB_DATABASE` (`arospe`) — this already happened once in this project and wiped the dev database (see [errors-log.md](../errors-log.md)). The only reliable way to target the testing database outside the test runner is to export `DB_DATABASE=testing` explicitly, or better, just invoke through the test runner itself.
4. **If you need to inspect or reset test data while debugging, run the actual test suite** (it resets its own database safely) instead of a manual `artisan` command against whatever database happens to be configured.

This is intentionally stricter than the general "confirm before destructive operations" git-safety guidance: here, prior approval never generalizes across commands or sessions, and the testing-database exemption is narrow — it covers only commands executed through Laravel's test runner, never a manually typed `artisan` command that merely references "testing."
