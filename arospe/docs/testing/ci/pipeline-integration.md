# Pipeline Integration

## Current state (real, as of this writing)

[`.github/workflows/tests.yml`](../../../../.github/workflows/tests.yml) runs on every push to `develop`/`main`/`master` and on every PR targeting `develop`/`main`/`master`/`workos`/`feature-entrega2-ARP`/`finalproject-ARP` (a work branch is validated through its PR only — listing it under `push` as well ran every workflow twice on the same commit, once per event), but only when the change touches `arospe/**` or the workflow file itself (a `paths:` filter — a docs-only change outside `arospe/` triggers no run). It is pinned to a single PHP version, **`8.5`** (2026-09-06) — matching the version this project actually develops and deploys with. There is deliberately no `strategy.matrix` any more: an earlier `['8.4', '8.5']` two-version matrix was dropped rather than narrowed to one entry, since a matrix with a single leg is pure indirection over a literal value. The relevant steps today:

```yaml
- name: Setup PHP
  uses: shivammathur/setup-php@...
  with:
    php-version: '8.5'
    tools: composer:v2
    coverage: xdebug
    extensions: imagick        # story 0019 — see below

- name: Install libheif AVIF encoder plugin  # 2026-09-06 — see below
  run: sudo apt-get update && sudo apt-get install -y libheif-plugin-aomenc

# ...

- name: Run Type Analysis
  run: composer types:check

- name: Run Tests
  run: php artisan test --parallel
```

A coverage driver (`xdebug`) is already installed by the `setup-php` step, but **the `Run Tests` step does not currently request coverage or enforce a threshold** — it's `php artisan test --parallel` with no `--coverage`/`--min`. There is no coverage gate blocking merges today. This file documents what adding one would look like; it is a proposal, not a change that has been made to `tests.yml`.

**`--parallel` since the test-performance review that measured this repo's real suite times** (see [commands.md#run-in-parallel](commands.md#run-in-parallel) for the numbers). No `--processes` is passed, so paratest auto-detects the runner's core count — `ubuntu-latest`'s standard hosted runner rather than a number hardcoded here that could silently stop matching if GitHub changes it. Unlike the Sail/WSL2-specific fix `--parallel` needed locally (`storage/framework/views` moved off the bind-mounted volume — see [errors-log.md](../../errors-log.md)), CI's runner has no bind mount in the loop at all: `actions/checkout` writes directly to the runner's own native filesystem, so this workflow was never exposed to that failure mode and needed no equivalent change.

**Database provisioning.** The job declares a `services.mysql` container (`mysql:8.4`, matching `compose.yaml`), gated behind a `mysqladmin ping` healthcheck the runner waits on before any step runs, plus a job-level `env:` block pointing `DB_CONNECTION`/`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` at it (`127.0.0.1:3306`, database `testing`, user `root`, empty password). Those are real process environment variables, so they take precedence over whatever the `Copy Environment File` step (`cp .env.example .env`) writes to disk — no `.env` edit happens in CI. `phpunit.xml`'s own `<env name="DB_CONNECTION" value="mysql"/>` / `<env name="DB_DATABASE" value="testing"/>` pins the same target for local runs, so CI and a local `php artisan test` always hit the same MySQL `testing` database regardless of a contributor's real `.env`. See [`ai-spec/tasks/ci-database-connection-gap.md`](../../../ai-spec/tasks/ci-database-connection-gap.md) for why this was needed — before this fix, neither CI nor a fresh local clone could open a database connection at all. What a contributor must provision locally to satisfy the same pin — and the `.env.testing` subtlety that trips up more than one `git worktree` sharing one MySQL instance — is in [commands.md's Database prerequisite section](commands.md#database-prerequisite).

> **`extensions: imagick` is a *correctness* input, not a convenience** (story 0019). `setup-php` does not install Imagick by default, and the Media Library's `.webp`/`.avif` conversions are generated through Intervention Image pinned to the **Imagick** driver — because GD on this platform has WebP support but **no AVIF support at all**, verified rather than assumed (`gd_info()` reports an empty `AVIF Support`). Without this one line the conversion tests cannot pass. The alternative — guarding them with `->skip(fn () => ! extension_loaded('imagick'))` — was considered and **rejected**, because it converts the single most important acceptance criterion of that story into a green tick asserting nothing, invisibly. Sail's own image already installs `php8.5-imagick`, so local runs were never affected and CI was the only gap. Note the line's *placement* is constrained too: it belongs on the existing, already-SHA-pinned `setup-php` step, above the step that writes Flux credentials to disk — see [security/ci-workflow-hardening.md](../../security/ci-workflow-hardening.md). This paragraph originally described the cost as "on every leg of a 3-version matrix" — stale since the matrix was dropped down to the single `8.5` version (2026-09-06); the install cost is now paid once per run rather than per leg.

> **`Install libheif AVIF encoder plugin` closes a GitHub-hosted-runner-only gap `extensions: imagick` cannot see** (2026-09-06). Since a recent Ubuntu runner image update, GitHub-hosted runners ship `libheif` with AVIF **decode** support only — the AVIF **encoder** plugins (`libheif-plugin-aomenc` and siblings) were split into optional packages Ubuntu does not install by default, confirmed against [actions/runner-images#13728](https://github.com/actions/runner-images/issues/13728) (closed upstream as "not planned", i.e. accepted behavior rather than a transient outage). Imagick still *registers* AVIF as a known format under this condition — `extension_loaded('imagick')` and `Imagick::queryFormats('AVIF')` both report success — but silently writes a **0-byte blob** the moment it actually encodes one, rather than throwing, which is why `tests/Unit/Actions/Media/GenerateImageConversionsTest.php`'s AVIF byte-signature assertion is what actually catches it, not the `extensions: imagick` step itself. Sail's own `docker/8.5/Dockerfile` installs `php8.5-imagick` via a plain `apt-get install`, which pulls the encoder plugin in as a `Recommends` dependency — so this gap was invisible locally and only ever surfaced on a real CI run. See [errors-log.md](../../errors-log/2026-09-01-to-2026-09-07.md#github-actions-ubuntu-runner-ships-libheif-with-no-avif-encoder-so-imagick-silently-wrote-a-0-byte-avif--2026-09-06) for the full incident. Like `extensions: imagick` above, this step is deliberately unconditional rather than `->skip()`'d when the plugin is missing — the same "a skipped assertion is a green tick proving nothing" reasoning applies.

> Note that since task 0006b, that plain `php artisan test` also runs the `Browser` testsuite, which is why the workflow carries an `Install Playwright Browser (Chromium)` step (elided from the excerpt above) between `Install Node Dependencies` and the Composer steps. Coverage gating is unaffected by it either way — see [frontend/playwright-setup.md](../frontend/playwright-setup/selectors-tagging-and-ci.md#ci-integration) for what CI does and does not cover on the browser side, and [security/ci-workflow-hardening.md](../../security/ci-workflow-hardening.md) for that step's supply-chain constraints.

## Other workflows: style check and production deploy

**[`lint.yml`](../../../../.github/workflows/lint.yml)** (workflow name `linter`) shares `tests.yml`'s exact trigger — the same `push` and `pull_request` branch lists and the same `arospe/**` path filter — and runs `composer lint` (Pint) on PHP `8.4`. Its auto-commit step is commented out, so it only reports; style is fixed locally. It still declares `permissions: contents: write`, which nothing uses any more — a known gap recorded in [security/ci-workflow-hardening.md](../../security/ci-workflow-hardening.md).

**[`prod.yml`](../../../../.github/workflows/prod.yml)** (workflow name `PROD`) is the production deploy, and it consumes the two workflows above as a gate rather than re-running them:

1. It runs only on a manual `workflow_dispatch`, and its first job is guarded by `if: github.ref == 'refs/heads/main'`. GitHub only lists a `workflow_dispatch` workflow once the file exists on the repository's default branch.
2. **`verificar-ci`** reads every run for the exact commit being deployed (`gh run list --commit "$SHA"`) and fails unless the latest run of each workflow named in `REQUIRED_WORKFLOWS` exists, has `completed`, and concluded `success`.
3. **`deploy`** writes the SSH key and a pinned `known_hosts` from secrets, then runs `deploy <sha>` on the VPS with that verified SHA — never "the branch tip".
4. `concurrency: prod-deploy` with `cancel-in-progress: false` serializes deploys without aborting one already in flight; `timeout-minutes: 30` bounds a hung one.

`REQUIRED_WORKFLOWS` is matched **case-sensitively against each workflow's `name:` field**, not its file name — today `"tests,linter"`. It originally read `"Tests,Lint"`, which matched neither workflow, so `verificar-ci` reported "not run" on every commit and no deploy could ever pass the gate. **Renaming either workflow's `name:` silently breaks the deploy gate**; update `REQUIRED_WORKFLOWS` in the same change.

## Proposed: adding a coverage gate

To make the [`--min=80` floor](commands.md#enforce-a-minimum-coverage-threshold) actually block merges, the `Run Tests` step would change to:

```yaml
- name: Run Tests
  run: php artisan test --parallel --coverage --min=80
```

Nothing else in the workflow needs to change — `coverage: xdebug` is already configured, and `--min=80` alone is sufficient: Pest exits non-zero when coverage falls under the threshold, which fails the step, which fails the job, which blocks the PR from being merged if this job is a required check on the target branch.

## What happens if the threshold isn't met, once this is wired up

1. The `Run Tests` CI step fails and the job shows red in the PR checks.
2. If `ci` is configured as a required status check on the branch protection rule for `main`/`master`/`develop`, the merge button is blocked until it's green again.
3. The fix is **not** "lower `--min`" or "add a trivial test to inflate the number" — per [philosophy.md](../philosophy.md) and [qa/what-not-to-test.md](../qa/what-not-to-test.md), the fix is to write a real test for whatever code path dropped coverage. If a reviewer sees a PR that pads coverage back up with meaningless tests instead, that's a [coverage-review-checklist.md](../qa/coverage-review-checklist.md) rejection, not an approval.

## Before actually enabling this

Since this repo's PR contract ([`docs/contracts.md`](../../contracts.md)) calls for asking before taking non-obvious actions rather than assuming: adding `--min=80` to the real workflow is a deliberate decision for whoever owns CI to make (it will start failing PRs the moment coverage is under 80%, which may or may not be true today — nobody has measured it yet with `php artisan test --coverage` locally). Run that locally first to see where this repo actually stands before wiring the gate into `tests.yml`.

_Last updated: 2026-09-29 — Added `finalproject-ARP` and the `arospe/**` path filter to the trigger description, fixed the relative link depth to `.github/`, and added **Other workflows** documenting `lint.yml` and the `prod.yml` deploy gate, including the `REQUIRED_WORKFLOWS` fix (`"Tests,Lint"` → `"tests,linter"`). Later the same day: dropped `workos`/`feature-entrega2-ARP`/`finalproject-ARP` from the `push` branch list of `tests.yml` and `lint.yml`, so a PR shows one run per workflow instead of two._

_Earlier revision notes: [testing--ci--pipeline-integration.md](../../history/testing--ci--pipeline-integration.md)._
