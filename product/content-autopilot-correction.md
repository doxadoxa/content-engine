# Avyo — restore the content autopilot

15 September 2026. Authoritative correction following the owner's review of the implemented product. Source correction, automated checks, authenticated local walkthrough and the authorized bounded provider validation are complete. Production activation remains separate.

Later owner feedback prompted a more visual, spacious landing page and a [proposed Starter/Growth offer at US$29/US$89](two-plan-pricing.md). The landing refresh is implemented; that two-plan offer is a proposal and has not changed the version 3 billing catalog described below.

## Product promise

Avyo researches, writes and publishes useful website content that helps a local business become discoverable in search engines and AI answers. The business manager sees the plan, changes direction when needed and understands the results. The software operates the SEO workflow.

The owner explicitly chose **automatic publication on the calendar by default, with an option to review first**. This supersedes the existing-page-first journey and zero-article offer in `seo-geo-focus-plan.md`. Social remains retired. Existing-page improvements, confirmed facts, AI accuracy, safe publishing and purchase measurement remain supporting capabilities.

The earlier implementation was technically tested but failed this product requirement. Its calendar was hidden, Content opened a page inventory, the new offer allowed zero articles, and onboarding ended at an audit. A manager was being asked to operate the implementation. Passing those tests did not validate the product direction.

## Manager's experience

1. Add the business website, confirm what the company does and connect its publishing destination. Choose automatic publishing or review first.
2. Avyo researches relevant customer questions and prepares a dated content plan. Research, planning and writing are visible work in progress, not separate jobs the manager must understand.
3. The Calendar shows planned topics, articles being written, items awaiting review when applicable, scheduled publications and confirmed published articles. Managers can add a topic, request a plan, change a publication time, pause or cancel it.
4. In automatic mode, eligible articles pass the same quality and allowance checks and publish at the authorized time. In review-first mode, an article waits for approval. A planning date alone is never treated as proof of authorization or publication.
5. Results show observed search traffic, AI mentions/citations and purchases where connected, with a clear period and honest missing-data states. Setting up purchase attribution is not a prerequisite for getting useful content.

Primary navigation: **Dashboard · Calendar · Content · Results**. Dashboard explains what Avyo is preparing, the next publication, completed work and specific exceptions. Technical source records, CMS object binding, audit details and provider costs belong in supporting views.

## Customer-facing message

The owner clarified that useful content is the method, while customers want more traffic and new clients. The landing therefore leads with **“Get found. Win more customers.”** Its story moves from reaching people looking for the business's services, to giving them confidence to choose it, to following traffic and connected sales. Research, articles and automatic publication explain how Avyo supports that journey. The calendar remains visible as proof of the workflow, rather than the headline benefit.

The page keeps its visual previews and spacious layout. Calls to action say “Get started”; Avyo does not build a replacement website. The message describes the customer's intended outcome without inventing achieved results or promising a fixed growth rate. Current scope remains discovery of a business and its website, not app-store optimization or guaranteed app downloads.

## Why choose it

The proposed advantage is useful content closely tied to a local business's real services and buyer questions, followed by improvements informed by observed results. Confirmed business information, corrections and service-page improvements strengthen the output underneath a simple content workflow. This is a product hypothesis to validate with pilot evidence, not a claim that competitors lack all of these capabilities.

BabyLoveGrowth's public content workflow already includes a content plan, article management and publication. Avyo must match the clarity of that basic job before additional capabilities count as advantages. Source checked 15 September 2026: [BabyLoveGrowth content plan](https://www.babylovegrowth.ai/docs/dashboard/content-plan).

## Delivery and verification

| Work | Required result |
|---|---|
| Manager interface | Calendar and generated content are first-class destinations; clear working actions and no technical setup wall |
| Article engine | Research → planning → generation works with social disabled, including new-project launch and later months |
| Package | A new version restores article and planning allowances without silently changing historical subscriptions; US$89 remains a provisional validation price |
| Scheduling | Explicit time/timezone, automatic/review-first mode, compatible verified destination, pause/cancel/reschedule, no early or duplicate delivery |
| Quality and allowance | Automatic and human approval share the same checks; retries and revisions cannot silently double-charge one article |
| WordPress/custom publication | Actual new-article intake, retained identities and receipts; existing-page editing does not substitute for creating an article |
| Business grounding | Current confirmed business information reaches article generation; changed information can prevent outdated automatic publication |
| Results | Published article URLs enter measurement without manual re-import; unknown observations remain unknown |
| Validation | Independent critique after each correction phase, relevant regression tests, full lint/types/build and local application verification |

Local background workers remain paused. The owner explicitly authorized the local test-account walkthrough and a separate provider experiment limited to four short answers plus one synthetic fact check, with no paid retries and a US$1/unknown-cost stop boundary. That experiment completed as recorded below. No production publication or unrestricted background generation ran as part of these checks.

## Acceptance test in plain language

A manager can explain what the product does after opening the Dashboard. They can see the upcoming articles directly in Calendar, change a date, choose review first, and understand why an article is waiting. A fresh website can obtain AI-created content without manually creating a fact register, selecting CMS object IDs or interpreting an SEO audit. With a working connection, authorized content publishes at the scheduled time and the manager can open the actual article. A page-improvement tool is available when useful, but it is not the main product journey.

## Implemented correction and independent review

| Phase | Implemented behavior | Review and verification |
|---|---|---|
| Manager workflow | Dashboard, Calendar, Content and Results are primary. The calendar shows planned topics and actual publication states. Add topic, prepare plan, review, request revision, change time/mode, pause/cancel and retry actions are connected to the real services. Readiness separates a selected preference from a verified publishing connection. | Independent interface/backend review resolved misleading approval counts, retry handling, project preference opt-in, timezone display, connection readiness and failed-delivery copy. Manager/Home/results subset: 24 tests / 279 assertions passed. |
| Content engine and offer | Research, planning and writing work with social disabled. Repeated requests reuse in-flight work. Prepared articles count toward capacity before further generation. Version 3 offers 30 articles and one plan, with three articles and one plan in the trial. Historical subscription versions remain intact. | Independent scheduling/interface critique completed; focused backend set: 110 tests / 419 assertions passed before the final combined gate. Price remains provisional; the separate real Stripe price has not been created here. |
| Scheduled publication | Explicit time, timezone and mode; automatic is the new-project default, review first remains optional. Old project flags and historical drafts do not create publication schedules. One approval allowance per article; retries reuse delivery identity. Pausing, rescheduling and delivery share guards against racing writes. | Independent transport/retry critique found no remaining material issue. Synthetic local scheduled-publication journeys passed; the WordPress receiver has 29 real receiver tests. Private custom-receiver evidence is retained locally. |
| Useful, current content | Confirmed business information is pinned for each writing run. Fact checking includes the article, summary, FAQ, structured data, author and link labels. Empty or punctuation-only verdicts cannot pass. Changed facts or edited public fields stop stale automatic publication. | Independent critique caught unchecked FAQ/summary claims, illustration overwrites/resealing, and duplicate image insertion on retries. These were fixed and re-inspected. Images preserve intervening edits and paid cost records; literal insertion also handles price headings such as `$100`. |
| Results | Published article URLs enter tracking automatically without fabricated page reads. Paused/existing page identities are preserved. Results distinguish observed search and AI data from missing connections; purchase tracking remains optional. | Transport/enrolment tests and independent review passed. No organic-growth or purchase uplift is claimed from synthetic checks. |

Receiver packages, limits and evidence: [WordPress validation](../app/packages/wordpress-receiver/VALIDATION.md). Private pilot integration artifacts are retained locally and are not included in this public repository. No production deployment ran.

## Final automated verification

- **2,230 tests passed / 10,289 assertions**, 118.18 seconds: `/private/tmp/avyo-content-correction-final-gate.log`. The final run includes all six illustration edit/retry cases, the expanded article fact check and schedule regressions.
- **Full Docker PHPStan passed** after resolving its reported billing-display and test-fixture type issues: `/private/tmp/avyo-content-correction-final-static.log`.
- **Full host PHPStan passed**, reporting zero errors: `/private/tmp/avyo-content-correction-host-static-final.log`.
- **Pint passed for all 1,101 files** after the final refinements: `/private/tmp/avyo-content-correction-pint-final.log`.
- **ESLint, Prettier, TypeScript and production build passed** in the final combined run; the build completed in 5.19 seconds. `git diff --check` also passed.
- The combined script initially returned exit 1 because its static check preceded the final type corrections. Its passing runtime/frontend results and the subsequent passing full static/formatting reruns are reported separately; this document does not mislabel that original exit as successful.
- The subsequent browser walkthrough exposed a misleading social-quota warning for the package that excludes social. The warning now omits retired/excluded allowances while retaining real article-exhaustion warnings. **67 billing tests / 231 assertions passed**, with scoped Docker/host PHPStan and Pint also passing. Independent read-only review approved the fix. Log: `/private/tmp/avyo-content-correction-browser-fix-tests.log`. This small correction followed the full 2,230-test run.
- The final calendar screenshot at approximately 1,040px window width exposed action buttons squeezing the month heading into a narrow column. The shared header now wraps its action group before compressing the heading. Independent source review approved the change; live Dashboard, Calendar, Content and Results screenshots confirmed readable headings/actions. Scoped ESLint/Prettier, full TypeScript and a fresh production build passed (5.21 seconds). This layout-only change followed the full runtime gate.

## Authenticated local acceptance

After the owner's explicit local-account approval, the in-app browser used the existing authenticated local session. The rebuilt public page explains research → content calendar → automatic publishing and optional review first. The populated synthetic project remained paused throughout the walkthrough.

| Screen or flow | Observed result |
|---|---|
| Dashboard | Four primary destinations, visible content actions, upcoming articles, review needs and observed results. A private pilot also showed the explicit publishing-preference setup step. No customer setting or content was changed. |
| Calendar | Three populated article entries distinguish published, scheduled and awaiting review. Publication dates and automatic/review-first modes are visible. |
| Article controls | Pausing an article, switching to review first, changing its time to 08:00 and approving it all persisted. The future article stayed scheduled; no browser action published it. |
| Content | The article list retained the changed schedule and readable content/statuses; existing-page improvements remain secondary. |
| Results | The article actually published during the local WordPress journey appears with its URL. Unavailable search/AI observations remain unknown; Google connection is optional for producing content. |
| AI accuracy | Original synthetic quotes, confirmed facts, dismissal/reopening history, canceled external handoff and separate recheck remain distinguishable. See [fixture and walkthrough](ai-accuracy-local-fixture.md). |
| Changed business facts | Old facts and quotes remain in history; the new Lisbon/Cascais comparison and two affected uses are visible. An assisted handoff is not represented as applied. See [fixture and walkthrough](fact-maintenance-local-fixture.md). |

## Bounded real-provider validation

Exactly one short answer from each configured ChatGPT, Gemini, Claude and Perplexity service completed, followed by one synthetic fact check through the real metered accuracy pipeline. **Total reported cost: US$0.110590**, with no paid retries. The checker found the intended exact-quote contradiction and recorded its usage/cost. The synthetic validation project ended paused with its fixture subscription canceled and no active work. Detailed receipts, provider limitations and per-call costs are recorded in [AI sampling validation](ai-sampling-validation.md).

These checks validate transport, retained evidence and the manager workflow. They do not establish an SEO ranking improvement, consumer-product parity for AI answers, or additional purchases.

## Activation and pilot boundary

Source implementation and the two previously blocked acceptance checks are complete. Local background workers remain stopped, production receiver deployment has not run, and a real Stripe price for the US$89 offer has not been created. The browser/provider approvals do not start ongoing paid generation or publish customer content.

The content pilot next needs its production article connection and activation of the agreed publishing preference. Claims about the unresolved Deep Clean inclusion rule require the owner's answer; other useful content can use documented services and customer questions. Purchase collection and elapsed observations are needed to evaluate acquisition results, and existing-page improvements remain optional supporting work. The [launch evidence register](seo-geo-launch-evidence.md) retains these external dependencies. No customer-growth result or external paid commitment is claimed.


## Two paid plans implemented — 16 September 2026

The [two-plan package](two-plan-pricing.md) is now version 4: Starter US$29/12 articles per billing month and Growth US$89/30 articles. Both keep the calendar and automatic publication with optional review. The selected price carries through signup and setup to trial checkout. AI observations are monthly versus weekly with shared manual/scheduled attempt limits. Growth includes four existing-page improvements. New billing-period planning handles late-month starts and short months; existing subscriptions remain pinned to versions 1–3.

Local Stripe credentials and the two new price IDs are absent. Real checkout activation is an external configuration step, not a completed payment test. No new paid provider calls, customer publications, background worker activation or external messages were performed for this implementation.

Verification details, remaining Stripe configuration and the exact regression results are recorded in [the two-plan verification record](two-plan-pricing.md#verification-record).

## Dashboard results restored — 17 September 2026

The dashboard now leads with AI visibility, Google clicks, search impressions, and recorded purchases. AI visibility has its own sidebar entry, an immediately visible service breakdown, and a link to the matching report. The content calendar, publishing controls, and upcoming work remain below the results. Search trends use stored daily observations; missing days remain gaps and measured zeros remain zero.

The earlier redesign had hidden saved AI evidence by reading only the new sampling tables. Earlier checks are visible again and remain clearly separate from current full-answer runs. Accuracy questions and single-question rechecks do not replace the discovery panel; a pending run does not erase the last recorded results. Where a project has no Search Console page measurements or purchase feed, those cards explain the missing connection instead of claiming zero traffic or sales. Private pilot measurements are retained locally.

Validation: 66 Docker regression tests / 603 assertions passed; changed PHP files passed PHPStan and Pint; frontend lint, formatting, types, and production build passed. Local dashboard and historical report agree. Browser checks at 390, 768, and 1440 pixels found no horizontal overflow, and visible desktop/mobile layouts were inspected. No new paid AI calls, real publications, or background worker activation occurred.

## Exact AI questions restored — 18 September 2026

AI visibility now shows the exact saved questions above the editing controls, including language, intent, per-service mention status, and check date. Search and language filters make a full saved-question set navigable. Missing answers remain distinct from answers that did not mention the business. Current full-answer checks show their own question list and answer links; questions saved for future checks are separate from earlier evidence. Reading the screen does not create a question version or buy a check.

Verification: 28 focused Docker tests / 276 assertions; frontend lint, formatting, types, and build; changed PHP static analysis and formatting; visible desktop and phone review. No paid checks, publications, or worker activation occurred.

## Search performance replaces Results — 18 September 2026

Results is now **Search performance**. Dashboard owns the overall progress summary; AI visibility owns the exact AI questions and service answers; Search performance opens directly on Google query and page detail. The duplicated AI, search and purchase overview and repeated article list were removed from this page. Dashboard search links and the primary navigation use the new name, while existing `/performance` links remain valid.

The Search queries and Pages views show clicks, impressions, click-through rate, average position, and available changes against the previous 28-day period. Search, page filtering, metric sorting and page-to-query drilldown are available. Queries are shown with their matching page instead of being misleadingly aggregated across pages. Missing current data remains unknown, observed zero remains zero, and earlier-only observations retain their previous values. Stale/incomplete reads suppress comparisons. Page improvement follow-ups and reporting details remain secondary expandable sections.

Search Console connection state is read from saved integration settings, independently of whether measurements have arrived. A project with monitored pages and no Search Console connection receives an empty state that explains this without claiming zero traffic. Opening the report does not request provider data. Owners can explicitly refresh connected active projects.

Validation: **25 Docker tests / 282 assertions passed** covering Search performance, measurement aggregation and Dashboard behavior. Changed PHP files passed Pint and host PHPStan. Frontend lint, formatting, TypeScript and production build passed. The authenticated local app was inspected, and read-only browser checks passed at 390, 768 and 1440 pixels with no document overflow. Browser-only synthetic responses verified populated tables, sorting, filters, page drilldown, period deltas, measured zero and previous-only data; no synthetic measurements were saved to the project. Mobile tables scroll within their panel. No paid provider calls, publications or background worker activation occurred.

## Current billing plan made explicit — 18 September 2026

Plan & usage now starts with a dedicated current-plan card: name, status, listed price and currency, article allowance, approvals used, remaining articles, and period dates. Earlier packages are clearly identified separately from the current Starter/Growth offers. A manually assigned package explains that automatic payments are not configured; custom packages display custom pricing rather than assuming the catalog amount is the negotiated price. Trial allowances and end dates stay separate from the selected paid plan. Expired trials and canceled plans do not advertise remaining articles as available.

Historical subscriptions retain their original package/version and are not silently migrated. No plan migration or payment action was performed. The long allowance explanation is collapsed, usage states remaining capacity explicitly, and the new offers are under Compare plans. Current paid offers carry a visible Current plan badge. Payment actions remain owner-only.

Validation: 68 Docker billing tests / 479 assertions passed, including five new current-plan regressions. Changed PHP passed host PHPStan and Pint; frontend lint, formatting, TypeScript and production build passed. Authenticated browser review and read-only checks covered 390/768/1440 pixels and browser-only fixtures for current paid, trial, expired trial, canceled, overdue, viewer, custom and missing subscription states. No real checkout, plan changes, provider requests or worker activation occurred.

## Billing design critique resolved — 19 September 2026

All five findings from the independent billing design review were addressed. Listed plan price is explicitly separate from automatic billing status, and new offers consistently show US$ currency. The current-plan summary leads with remaining articles, combines period dates, and removes repeated article counters from Other included usage. The phone summary fell from roughly 775px to 399px at a 390px viewport.

Starter and Growth emphasize 12/30 articles per billing period and monthly/weekly AI visibility checks. Comparison rows align, including an explicit Not included value for Starter page improvements. Before switching, customers can inspect allowances lower than their own package. These comparisons use the subscribed package with negotiated overrides, separately from temporary trial allowances; missing historical features are not presented as unlimited benefits. Team members and Assistant messages replace internal labels, and short definitions explain both AI counters, including failed attempts.

Validation: 68 Docker billing tests / 489 assertions passed; changed PHP passed Pint and host PHPStan; frontend lint, formatting, TypeScript, production build and diff checks passed. Read-only browser checks covered 390, 768, 1024 and 1440 pixels, aligned desktop rows, keyboard disclosure controls, dark-mode layout and eight subscription states. The same design critic reviewed the revised screenshots and found all five issues addressed with no material remaining gaps after the comparison-introduction wording was corrected. No subscription changes, checkout submissions, paid provider calls or worker activation were performed.
