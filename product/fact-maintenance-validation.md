# Fact maintenance implementation and validation

F5 adds an owner-reviewed workflow for checking selected tracked pages against selected, current confirmed business facts. A new explicit request creates one durable check per page. The check captures current public text, preserves exact quotations and Unicode offsets, records the selected fact versions, and retains the checker’s provenance, coverage and omissions. An interrupted attempt is not automatically bought again.

The Business Facts screen links to `/fact-maintenance`. Owners can inspect supported, contradicted and insufficient-evidence findings, acknowledge them, dismiss them with a reason, explicitly reopen a dismissed finding, or request a bounded correction. Supported page content enters the existing reviewed opportunity workflow. Footer, structured-data and unsupported source changes require a concrete assisted handoff with exact before/after text and instructions. A handoff does not claim application or verification.

Changing or retracting a fact records impacts on earlier checked claims and already-applied proposal references. Reconciliation also finds a publication receipt that arrives after the fact changed. History retains the old fact and quote. Fresh checks distinguish an exact quote remaining, an exact quote absent, a current checker’s reworded candidate, and unknown coverage. Absence alone never means that a factual error was corrected. Comparisons retain the last claim-bearing baseline across an interrupted check and remain within the same canonical URL and language.

All new checks require current generation entitlement; queued checks recheck it before provider work. Saved evidence remains readable. Project locking serializes check creation, dispatch linkage, fact changes, impact reconciliation and owner decisions. Migration 191000 enforces the exact tenant/page/snapshot relationship between a check and its claims, and tenant-scoped impacted page references.

## Recorded verification

- Canonical local Docker feature suite: **24 tests / 138 assertions passed**, including the final paused-project late-receipt regression and shared spending integration. Log: `/private/tmp/avyo-fact-maintenance-final-tests.log`.
- Scoped Docker and host PHPStan passed. Logs: `/private/tmp/avyo-fact-maintenance-final-phpstan.log`, `/private/tmp/avyo-fact-maintenance-final-host-phpstan.log`. The host invocation used `--debug` after the normal invocation exited without diagnostics.
- Scoped Pint, ESLint and Prettier passed. Full TypeScript and production frontend build passed during the F5 validation pass. Shared source changes after those checks belong to the combined final gate.
- Local main migrations 190000 and 191000 applied. Only local databases were used.
- Regressions cover immutable inputs/history, exact surface coverage, partial JSON capture, current-fact and page-identity races, review idempotency/reopening, retractions, concrete assisted handoffs, late publication impacts, explicit new checks after lost attempts, paid pipeline metering, billing refusal, stable dispatch linkage and database rejection of cross-page/cross-tenant references.

## Review and evidence limits

The independent root reviewer identified two F5 issues: concurrent dispatch could create duplicate run records, and several evidence references lacked composite database constraints. Both fixes and regressions are implemented, and the root reviewer approved their source after reinspection. A separate billing review identified missing generation entitlement gates across the newly introduced workflows; F5 kickoff and queued-worker guards are included in this validation. Impact reconciliation also covers paused projects without dispatching their queued provider work.

Authenticated browser validation completed on 15 September 2026 after the owner explicitly approved the local test-account check. The earlier rejected attempt did not run; the later walkthrough used the existing authenticated session. The original evidence/handoff, fresh Lisbon/Cascais comparison, two affected uses and retained check history were inspected in the paused synthetic project. No real recheck or page edit ran during this walkthrough. Details are in [the fixture guide](fact-maintenance-local-fixture.md).

The final F5 suite also exercised the shared checker’s durable provider receipt integration. Its independent economics review is recorded in `billing-economics-review.md`; the combined final software gate remains separate. The paused local synthetic fixture is documented in `fact-maintenance-local-fixture.md`. No production fact was confirmed, page changed, provider sample bought, or customer outcome established by this fixture work.
