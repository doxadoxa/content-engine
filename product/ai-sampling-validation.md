# AI sampling and factual review validation

Status as of 15 September 2026: the bounded software implementation, independent source reviews, and authorized paid-provider validation have passed. Authenticated review of the populated answer screen is tracked separately by the root task. This is not a claim that the entire SEO/GEO product or customer pilot is complete.

## Delivered behavior

- Stable, owner-reviewed and versioned questions, model panel, market and settings. Discovery metrics separate brand mentions from website citations; branded factual questions are excluded from discovery denominators. Incomplete responses, missing models and unknown outcomes remain visible. Rechecks preserve the original instrument and create new observations.
- Complete returned final answer text, sections, citation annotations and provider metadata are retained. Missing completion or price metadata stays unknown. This measures the selected API instrument; it does not establish what a consumer assistant showed a customer.
- A shared metered checker assesses immutable source sections against current confirmed business facts. Exact Unicode quotations, fact versions, citation references, coverage and checker provenance are validated. Context overlaps preserve boundary-spanning claims. A fact changed during a call invalidates current findings while retaining cost and old proposed evidence.
- Findings require owner review. Review history is retained; a stale review token cannot reverse a newer decision, and reopening a dismissal is explicit. Queued work rechecks current billing and owner eligibility. Checker calls recheck spending eligibility between calls.
- A separately confirmed discrepancy in a freshly captured owned page can enter the existing bounded proposal workflow. Correct pages receive no invented edit. Unsupported footer/structured-data surfaces or excessive quote scope produce a manual owned-page handoff with the exact evidence. External handoffs remain drafts; no message is sent.
- An explicit correction recheck buys a new observation of the same question and checks the new answer against current facts. It never automatically claims that the issue was resolved.
- Immutable answer fees and durable checker-call receipts survive the gap before pipeline totals are written. Receipts pin reported tokens and the run's price list. Unknown/unpriced attempts and ambiguous historical run links keep the report incomplete; normal rollups are not counted twice. Linked costs use the originating step's accounting window, which may differ from a provider's charge date.

## Recorded verification

All runtime suites used the canonical Docker application and isolated test database. Suites overlap and should not be added together.

| Verification | Recorded result |
| --- | --- |
| Stable sampling/provider/checker and legacy compatibility suite | 79 tests, 358 assertions passed |
| Final checker/sampling/accuracy subset before spending additions | 34 tests, 183 assertions passed |
| Spending, accuracy, sampling, checker, legacy pipeline/assistant metering, and fact-maintenance suite | 100 tests, 458 assertions passed |
| Final duplicate-dispatch cost regression and stable sampling suite | 21 tests, 96 assertions passed |
| Scoped Docker PHPStan and Pint | Passed |
| Visibility ESLint, global TypeScript, production build | Passed; build 5.15 seconds, final token-usage label rechecked with ESLint and TypeScript |
| Independent Milestone A source review | Passed after concurrency, current-fact race and contextual-window fixes |
| Independent Milestone B source review | Passed after stale-review, dispatch, eligibility and assisted-handoff fixes |
| Independent spending review | Duplicate historical dispatch finding fixed and re-inspected; accounting-window limitation exposed |

The 100-test command covered `tests/Feature/Metering/ProviderSpendTest.php`, `tests/Feature/Visibility/AiAccuracyWorkflowTest.php`, `tests/Feature/Visibility/StableSamplingTest.php`, `tests/Feature/Facts/AssessFactClaimsTest.php`, `tests/Feature/Pipelines/MeteringTest.php`, `tests/Feature/Assistant/AssistantSpendTest.php` and `tests/Feature/FactMaintenance`. The final 21-test subset re-ran the spending and stable-sampling files after the duplicate-run fix.

## Real-provider validation boundary

Read-only model inventory previously confirmed configured models for ChatGPT, Gemini, Claude and Perplexity. Capability inventory is not evidence of a successful paid answer. Provider request fields and response envelopes follow the primary [ChatGPT](https://docs.dataforseo.com/v3/ai_optimization-chat_gpt-llm_responses-live/), [Gemini](https://docs.dataforseo.com/v3/ai_optimization-gemini-llm_responses-live/), [Claude](https://docs.dataforseo.com/v3/ai_optimization-claude-llm_responses-live/) and [Perplexity](https://docs.dataforseo.com/v3/ai_optimization-perplexity-llm_responses-live/) documentation. Full task cost is distinct from token-only provider cost.

The user subsequently explicitly authorized four sequential real answer requests, at most one per configured platform, followed by one synthetic factual check through the actual metered pipeline. The executed single-use harness refreshed inventory immediately before each answer, applied supported output caps, disabled paid retries and stopped before any next request if reported spending reached US$1 or an outcome/price became unknown. Customer facts and publication state were not changed.

An earlier automatic approval review rejected a command before it ran because the authorization then recorded did not cover paid external AI requests. That rejected command made zero paid requests and zero validation-project mutations. The later explicit user authorization covered the bounded execution below. The separately authorized [synthetic local UI demo](ai-accuracy-local-fixture.md) still uses fake providers only and must not be presented as a paid result.

## Executed paid check — 15 September 2026

Canonical Docker execution made one bounded request to each configured provider and one synthetic fact check through the metered pipeline. Fresh inventory returned final answer text, citation metadata, resolved model identity and a full task fee. Provider fees, reported tokens and spending reads are private operational evidence retained locally. This is not a reconciled provider invoice or a representative article-production cost.

The checker used the existing `ai_accuracy` → `AssessFactClaims` metered path, with one actual gateway call, one successful step attempt and one durable provider receipt. Its clearly synthetic input, “Synthetic Beacon Validation serves Porto.”, was correctly marked as contradicting the fixture fact that it serves Lisbon only and does not serve Porto. The result preserved the exact quotation and current fact version, covered all 41 source characters, and remained a machine-proposed finding requiring owner review. None of the four real answers was substituted with this fixture or automatically assessed.

All four providers omitted finish metadata: `completion_state: not_reported` remains unknown, rather than being relabeled as a guaranteed untruncated response. Gemini did not accept the configured country control; the Lisbon location remained in its prompt. Web search was reported by all four. The requested word limit and citation count are instructions, not guaranteed response shape; some answers included additional sources. These are connectivity, retention, grounding and accounting checks, not evidence of customer visibility gains or a broader accuracy rate.

The synthetic validation project ended **paused**, with its fixture subscription canceled. Every dispatch was intercepted within the validation process; no worker jobs or paid retries were queued. Horizon and scheduler were confirmed stopped before and after execution. No publication, customer data, authentication state or main application source was changed.

Private evidence is retained locally and is not included in this public repository. It includes the complete returned answers, inventories, request metadata, fees, synthetic checker input/output, durable receipt, independent spending read and single-use harness. A new paid run requires new explicit authorization.

The root task owns authenticated final UI verification and records that result separately.
