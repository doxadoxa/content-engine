# Local fact-maintenance validation fixture

The project **SYNTHETIC — Fact maintenance validation** is paused and contains only fabricated evidence for UI validation. No real business fact, purchase, page or provider answer was changed. No authenticated session or selected project was changed during setup.

- Project: `01m2jbcwc89zf9d1zcrnrvsat3`.
- Tracked page: `01m2jbcwfsr0tem9xfn4ydb96j`; source is the deliberately nonexistent `https://f5-maintenance.invalid/service`, supplied by a local HTTP fake during setup.
- First check: [original synthetic evidence](http://localhost:8091/fact-maintenance/01m2jbcwgnqgdfw2ez3n69268r).
- Fresh check: [synthetic recheck](http://localhost:8091/fact-maintenance/01m2jbcwjefcppv6yj2sexszmd).
- Overview: [fact maintenance](http://localhost:8091/fact-maintenance), after selecting the synthetic project in an authorized local session.

The first check contains a main-content claim and a distinct footer claim about Porto, compared with a synthetic Lisbon-only fact. Its footer review preserves a concrete assisted handoff with exact replacement text and editing instructions. That handoff was never executed.

The synthetic fact then changes to Lisbon and Cascais, producing two impact records while preserving the historical fact and quotes. A fresh captured fixture changes the corresponding main/footer text. The second check preserves supported exact quotations and offers reworded claim candidates for review; it does not automatically mark the historical handoff applied or claim a verified correction.

Setup recorded four claims, two fact-change impacts, one assisted handoff and zero real provider calls. The fake model’s reasoning is clearly labeled synthetic. The setup script is `/private/tmp/avyo-fact-maintenance-fixture.php`; its result is `/private/tmp/avyo-fact-maintenance-fixture.json`.

The local seed account has owner access to this fixture. An earlier sign-in attempt was rejected before execution for missing explicit authorization; the owner subsequently approved the local browser check. The walkthrough used the existing authenticated local session on 15 September 2026 and kept this project paused.

## Completed browser walkthrough

The original check displayed the Lisbon-only fact, exact Porto statements, stale-fact warning and retained footer handoff with the replacement “Lisbon visits are available.” The fresh check displayed the current Lisbon/Cascais fact, two supported quoted candidates and separate comparisons with the earlier statements. The overview showed both affected uses and both historical checks. No new check was bought, no handoff was executed and no customer fact or page was changed.
