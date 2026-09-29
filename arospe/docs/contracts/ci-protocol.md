# Contracts — CI / GitHub Actions review protocol

> Part of [Contracts](../contracts.md). **Read this part when:** you diagnose a failing pipeline or review CI. The other parts are listed in the [hub](../contracts.md#rules).

### CI / GitHub Actions Review Protocol

When asked to review this repository's CI, diagnose a failing pipeline, or explain "why did the build break," treat this as an investigation with a fixed order rather than an open-ended "go look at GitHub Actions" task. This project's actual CI configuration, coverage enforcement state, and known environment findings are documented in [testing/ci/pipeline-integration.md](../testing/ci/pipeline-integration.md) and [testing/ci/commands.md](../testing/ci/commands.md); this rule governs your *behavior* while reviewing CI, not the pipeline's own configuration — don't restate those pages' content here, follow them.

Follow this protocol:

1. **List the recent runs for the current branch first**, not the whole repository's run history:

   ```bash
   gh run list --branch $(git branch --show-current) --limit 5
   ```

2. **If a run failed, view only the failed step's log**, not the full log of every job and step — the failed-only view is what you need to diagnose, and the full log is mostly noise:

   ```bash
   gh run view <run-id> --log-failed
   ```

3. **Reproduce the failure locally before touching any code.** A CI failure is a claim about this repository's state, not yet a diagnosis — confirm it locally first, using this project's own commands rather than a generic equivalent (`npm test` means nothing here; this is a Pest/Laravel project):

   ```bash
   php artisan test --compact --filter=<TestName>   # a failing test — narrow first, then run the full suite unscoped per the Full Test Suite Gate Rule above
   vendor/bin/pint --format agent                    # a formatting/style failure — unscoped, not --dirty, to match what CI actually checks
   composer types:check                              # a Larastan/static-analysis failure
   ```

   See [testing/ci/commands.md](../testing/ci/commands.md) for the full local command reference (single test, single file, coverage, parallel run) and [conventions/base-standards.md](../conventions/base-standards/workflow-and-quality-gates.md#quality-gates) for which of the three quality gates a given failure belongs to.

4. **Fix the real cause, not the test — unless the test itself is what's wrong.** Apply the same distinction [workflow.md](../workflow.md) Phase 3 already draws between a "Test issue" and a "Code issue": a test asserting a genuinely wrong expectation is fixed as a test change; application code that violates a real, correctly-asserted contract is fixed as an application change. Don't default to loosening, skipping, or deleting an inconvenient assertion just because that's the faster way to turn CI green — this is the same bias the Full Test Suite Gate Rule above already states as "a failing test blocks closure regardless of whose it is."
5. **Push the fix once it's genuinely ready, and re-invoke `/watch-ci after-push`.** Once a real cause has been diagnosed (steps 1-3) and fixed for the right reason (step 4), pushing it and re-triggering the pipeline are ordinary next steps, not actions that require asking first. Re-run `/watch-ci after-push` after every push until the pipeline is green — the diagnostic discipline in steps 1-4 is what earns the push, not a separate approval.

This protocol is diagnostic-first by design: steps 1–3 exist to establish, cheaply and locally, exactly what is broken before any code changes are made — the same "verify before acting" instinct behind the Destructive Database Command Rule and the Full Test Suite Gate Rule above, applied here to a CI run instead of to the database or to task closure.
