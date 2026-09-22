# Synthetic AI accuracy demo

Prepared on 15 September 2026 at 11:04 UTC for local visual review. This is synthetic UI evidence, not a customer result or a paid-provider validation.

## Project and access

- Project: **SYNTHETIC — AI Accuracy UI Demo**
- Project ID: `01m2jbv2mjdxhkqbncq9cntgtn`
- Slug: `synthetic-ai-accuracy-ui-demo`
- State: **paused**; its local factory subscription is **canceled**, so new paid work is refused.
- Unfinished pipeline runs: **0**.

After the separately authorized sign-in and project selection, choose this project and open:

- [Original synthetic answer and review history](http://localhost:8091/visibility/answers/01m2jbv2r7y9rjfex86qk1s4ab)
- [New synthetic answer from the explicit recheck](http://localhost:8091/visibility/answers/01m2jbv312cbw7k0n357g7vdrq)
- [Sampling history](http://localhost:8091/visibility)
- [Confirmed synthetic business facts](http://localhost:8091/business-facts)

Preparation did not sign in, change the selected project, change customer facts, or navigate a browser. Reading a fixture URL while another project is selected should remain inaccessible through normal tenant isolation.

## What to verify on the original answer

The complete saved answer starts with a synthetic-fixture label and deliberately says **“Synthetic Beacon Cleaning serves Porto.”** Its citation is a synthetic directory reference that was never fetched. The current owner-confirmed fixture fact says **“Synthetic Beacon Cleaning serves Lisbon only.”**

The review history contains an initial dismissal followed by an explicit reopening and confirmation. The external handoff is only a draft. Its manual history explicitly records that the synthetic draft was canceled and never sent.

Two owned-page assessments demonstrate different outcomes:

| Fixture page | Page ID | Assessment ID | Expected result |
| --- | --- | --- | --- |
| Correct main content | `01m2jbv2vm75zxzmbpg5063bxn` | `01m2jbv2wexfw09ph88jt10x7b` | No contradiction and no proposed edit; the saved supported claim matches the fact. |
| Incorrect footer | `01m2jbv2xsvd1cbbrfbxmsd2tr` | `01m2jbv2ygdtcbazayexf9002y` | One separately confirmed contradiction; an assisted owned-page handoff retains the exact quote and current fact because the footer is outside the bounded editor. |

No opportunity or publication was created. The fake page origin is a numeric public-shaped address solely to avoid DNS lookups; its HTML came entirely from the HTTP fake. Do not treat that address as a real business website or open it as part of the review.

The explicit recheck preserves the same question and creates a second answer containing the Lisbon-only statement. Its new assessment is saved separately. This demonstrates observation history, not proof that a real external correction succeeded.

## Retained identities

| Record | ID |
| --- | --- |
| Original answer | `01m2jbv2r7y9rjfex86qk1s4ab` |
| Original sampling run | `01m2jbv2pqzxs764hb3104v8jm` |
| Original accuracy assessment | `01m2jbv2s34bftnc4cet034cd6` |
| Original discrepancy | `01m2jbv2swgae3m5697kfszvm4` |
| External handoff | `01m2jbv2th161fw0nyhx8azex3` |
| Assisted owned-page handoff | `01m2jbv30622tegkwa13at7rbs` |
| Recheck sampling run | `01m2jbv30gvs2630vv1f1619kw` |
| Recheck answer | `01m2jbv312cbw7k0n357g7vdrq` |

## Execution receipt

- Fake answer requests: **2**.
- Fake checker requests: **6**.
- Fake HTTP requests: **4**.
- Real provider requests: **0**.
- External HTTP requests: **0**.
- All four accuracy assessments: **complete**.
- Opportunities created: **0**; publications created: **0**.
- Sign-in or selected-project changes: **none**.

The fake model returned deterministic test usage and used a zero-cost synthetic price entry in the preparation process only. These records are not provider invoices or evidence of real operating cost.

The script is retained at `app/storage/app/private/ai-accuracy-local-fixture.php` in the workspace, and its receipt is stored inside the application container at `/app/storage/app/private/ai-accuracy-local-fixture-receipt.json`. Both are explicitly local test artifacts. The preparation refuses to create a duplicate project with the same slug.

Automatic review initially flagged cleanup as potentially global. The operation was narrowed to explicit synthetic `project_id` predicates before the successful run. This separate no-network fixture preparation is distinct from the subsequently authorized real-provider experiment and authenticated visual review.

## Completed browser walkthrough

After the owner explicitly approved the local test-account check, the existing authenticated local session was used on 15 September 2026 to inspect both saved answers. The original screen retained the full synthetic Porto answer, Lisbon-only confirmed fact, exact contradiction, dismissal/reopening history, supported main-content assessment, footer handoff and canceled external draft. The separate recheck retained its own Lisbon-only answer and supported assessment. No external handoff was sent, no provider recheck was bought and no customer fact was changed. The project remained paused with its fixture subscription canceled.
