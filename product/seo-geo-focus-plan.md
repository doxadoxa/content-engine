# Avyo — SEO/GEO focus and existing-page improvement plan

> Superseded for the primary product journey and offer by [the owner's content-autopilot correction](content-autopilot-correction.md), 15 September 2026. AI-created content and automatic calendar publishing are the core product. This document remains implementation history for supporting SEO/GEO capabilities.

Date: 15 September 2026

Status: Finalised direction; phased implementation is in progress. See [implementation and verification](seo-geo-implementation.md).
Basis: Product discussion, owner decisions, and repository review on 14–15 September 2026. Price benchmark checked on 15 September 2026.

## 1. Product decision

Focus the commercial product on improving a business website through SEO/GEO. Freeze expansion of social creation, publishing, listening, and engagement.

The target market is **local small and medium businesses that want to be found in AI answers and search engines and gain new customers**. The first product bet is helping these businesses identify, approve, publish, and measure useful improvements to pages they already own. New articles remain available when there is a specific content gap. A daily article quota stops being the organising principle.

The primary business outcome is **purchases**, especially purchases from new customers. Record purchase value where available. Enquiries, booking requests, traffic, rankings, and AI citations are supporting signals and must not be reported as purchases. Purchase tracking is not in place on the initial pilot according to the owner, so establishing it is part of the first delivery milestone.

This document is the current planning reference for product direction and future scope. It supersedes the broad search-and-social roadmap and competitive assumptions in `spec.md` where they conflict. Earlier phase and feature documents remain implementation history. Writing this plan does not change application behaviour, subscriptions, or customer access.

### Decisions confirmed by the owner

| Decision | Agreed direction |
|---|---|
| Target customer | Local small and medium businesses seeking new customers through AI and search visibility. |
| First pilot | A private internal site. No external pilot customers have been identified yet. |
| First native website integration | WordPress. Keep the existing webhook route for custom websites. |
| Initial operating model | Assisted: the owner is willing to help review recommendations and apply changes. |
| Primary success outcome | Purchases; tracking still needs to be established. |
| Pricing direction | Benchmark BabyLoveGrowth and test a slightly lower price; see the proposed price below. |
| Social dependency | Nobody currently depends on the social features. Remove them from the active product and stop their background work. |
| Delivery team | The owner and Codex together. The owner leads product decisions and business access; Codex handles implementation and verification with the owner. |

### Initial implementation defaults

- Use the private pilot's local cleaning-service context to make the first workflow concrete; this is an initial use case within the broader local-business market, not a permanent restriction to cleaning companies.
- The public site presents a Lisbon business. Confirm the priority locale from the site's existing configuration and search data; preserve all existing languages. Start measurement and page changes in one selected locale rather than creating a new multilingual research project.
- Prioritise businesses with existing pages and search history for the first external cohort. Sites with little data still get an honest content review, with lower confidence in performance predictions.
- Validate the workflow on the private pilot first. Recruit an external cohort of up to five local businesses after the first usable internal cycle. External payment commitments are not a prerequisite for beginning the internal implementation.
- Do not assume the private pilot uses WordPress. Its custom-site delivery path must be checked independently, and WordPress must be tested on a representative WordPress installation before being advertised as supported.
- Internal usage can validate functionality, review effort, and tracking. It cannot establish external willingness to pay or retention.

No deadline, weekly capacity, or development budget was specified. Use milestone gates, assign work to the owner and Codex, and estimate dates after the booking/payment and WordPress feasibility checks. Customer outcomes and pilot thresholds remain hypotheses to measure.

### Price to test

Use **US$89 per site per month** as the provisional external pilot price. This is about 10% below the **US$99 per month** Grow price displayed by [BabyLoveGrowth](https://www.babylovegrowth.ai/en/pricing) on 15 September 2026. This specific price is a planning recommendation implementing the owner's direction; it is not validated willingness to pay or a live price change.

Compare the same currency, monthly billing basis, and tax treatment before publishing a price-comparison claim. The application currently defaults to EUR: do not silently relabel US$89 as €89 or change existing subscriptions. Decide the localised checkout amount and any currency migration during billing implementation. The benchmark is a reference, not a promise to match BabyLoveGrowth's article count, backlinks, guarantees, or other inclusions.

Start with one clear per-site offer. Determine its included page-improvement allowance from the internal pilot's real review/support cost before opening paid external checkout. Avoid unlimited manual work. Price is a supporting advantage; the principal reason to buy remains useful improvements and visible business outcomes.

Implementation decision, 15 September 2026: the version 2 validation offer is US$89, four first-accepted existing-page improvements per billing period, 20 tracked pages, one language, two seats and one website connection. The three-day payment-method trial includes one first-accepted improvement. Later revisions/retries/recovery of that proposal consume no extra unit; rejected/no-change drafts consume none; new articles are excluded. This makes the provisional scope explicit but does not replace the required cost and external-demand validation. Existing version 1 subscriptions retain their original currency and access. See [offer and actual launch evidence](seo-geo-launch-evidence.md).


### Positioning to test

Avyo helps local businesses improve their website for search and AI discovery, using their real business facts and measuring the purchases that follow.

The differentiation to test is a complete workflow for a local business's existing service pages: identify a commercially relevant gap, propose an edit supported by confirmed facts, help the owner publish it, and report subsequent purchases. A lower price supports this offer; it is not the main reason to choose Avyo. This is a positioning hypothesis, not a verified claim that BabyLoveGrowth lacks these capabilities.

Demonstrate this with a real page, the reason for changing it, the exact proposed edits, and subsequent results. Avoid claims of guaranteed rankings, leads, or AI recommendations.

**First delivery priority:** establish how a completed the private pilot sale is recorded, connect that measurement to the selected pages where possible, and take one useful existing-page improvement through review, publication, and verification. This gives the owner and Codex a concrete first milestone before expanding the product or recruiting the external cohort.

## 2. Scope changes

| Area | Decision | Required change |
|---|---|---|
| Business knowledge and Brand Brief | Keep and deepen | Store confirmed facts with sources and dates; use them in proposed page edits. |
| Existing-page inventory | Make core | Import and inspect selected pages, including service pages and articles written before Avyo. |
| Planning | Refocus | Rank specific page opportunities. New content competes with improvements to existing pages. |
| Drafting and approvals | Keep and adapt | Review a bounded change against the current page, with evidence and an explanation. |
| Website publishing | WordPress first; retain custom webhooks | Support updating existing WordPress content and verifying the result. Verify what custom receivers can update before promising the same workflow there. |
| Search performance | Deepen | Connect relevant search queries and page performance to opportunities across the selected inventory. |
| Business-outcome measurement | Add purchase tracking first | Verify actual completed sales, deduplicate transactions, and report observed purchases/value by landing page where supported. Separate new-customer purchases when the source can identify them. |
| Technical audit | Supporting diagnostic | Surface issues that affect selected pages. General site repair automation is deferred. |
| AI visibility | Keep as supporting measurement | Show sampled answers, sources, dates, and limitations. Defer a larger monitoring product. |
| New articles | Keep selectively | Create when the opportunity requires a new page and there is enough evidence to write it. |
| Multilingual support | Preserve existing support | Pilot in one language; defer new locale-specific research and cross-market optimisation. |
| Social studio, carousels, video, listening, replies | Remove from the active product | Nobody depends on them. Stop the full workflow, including background work and assistant actions; preserve historical data without building an ongoing social tier. |
| General assistant | Narrow its role in the new experience | Explain an opportunity, gather a missing fact, revise a change, or explain a result. |
| Billing | One focused per-site offer | Test the US$89 reference price with an explicit work allowance. Remove social promises and resolve checkout currency before selling the new plan. |

### Explicitly deferred

- Backlink exchange networks, digital PR operations, Reddit/Quora agents, and social distribution.
- Google Business Profile, review management, local listing management, and Maps rank tracking.
- Broad competitor intelligence, large-scale crawling, or a replacement for a full SEO research suite.
- Arbitrary theme/code changes, page redesign, automatic redirects, merging/deleting pages, and experimentation infrastructure.
- A general CRM integration suite, attribution across multiple visits, call tracking, and cross-device attribution. A small booking/payment event integration or manual reconciliation for the pilot is in scope because purchases are the chosen outcome.
- An agency suite, white-label expansion, more creative formats, and many CMS integrations.

These may be valid businesses or later features. They do not belong in the first release.

## 3. First user journey

1. **Set the objective.** Add the site, choose the service to promote, and identify the authoritative completed-sale event. A request for a quote or a booking button click is not that event.
2. **Connect and confirm.** Connect Search Console and GA4, establish purchase tracking, and confirm a few business facts. Show explicitly which information is missing. Read-only diagnosis can start before purchase tracking is ready, but purchase-impact claims cannot.
3. **Choose pages.** Select an initial set of roughly 5–20 important pages. Mark the service pages that matter commercially.
4. **Review opportunities.** Receive at most five prioritised suggestions, each naming a page, evidence, a proposed action, and its uncertainty.
5. **Review one change.** Compare the current content with proposed edits. Correct facts, request revisions, accept, or dismiss with a reason.
6. **Publish.** Use an assisted handoff or a verified existing custom receiver on the private pilot, then the supported WordPress connection. Publication is a separate action from approval.
7. **Verify and follow up.** Verify the intended change is live. Show the baseline, publication date, and observed subsequent results. Recommend another action only when there is sufficient evidence.

Illustrative opportunity, not a measured finding: a service page receives relevant impressions but omits the service scope and pricing conditions buyers ask about. Avyo proposes a supported clarification and a relevant internal link, then follows search clicks and actual purchase outcomes. Requests and bookings remain separately labelled intermediate steps.

## 4. What already exists and what must change

Code observations describe implementation, not independently verified production performance.

| Foundation | Current boundary | Change needed |
|---|---|---|
| `SitePage` and `SiteLibrary` | Discover existing pages; commercial bodies are retained, editorial bodies generally are not. | Capture a separate, versioned editing snapshot of selected pages, including editorial content. |
| `ContentItem`, generation, review, delivery | Manage content created inside the engine. | Represent changes to external existing pages without pretending the current public page is an unpublished draft. |
| `FetchPerformance` | Selects published/refreshing `ContentItem` URLs. | Measure selected existing pages as well as Avyo-managed content. |
| `GoogleSearchConsole` | Per-page daily performance and separate brand-query readings. | Add bounded query-to-page measurements for opportunity diagnosis. |
| `DetectDegradation` | Detects decay, engagement decline, and near-ranking opportunities on managed content. | Reuse signals in a broader opportunity model; diagnose a cause before deciding to rewrite. |
| `GoogleAnalytics` | Page engagement and aggregate project key events. | Add validated purchase count/value reporting and landing-page context where available. A generic key event does not establish a purchase. |
| `WriteFixPlan` | Produces a prioritised technical work order. | Link relevant findings to page opportunities; retain handoff for unsupported repairs. |
| `ChannelPublisherRegistry` | Registered push publishers are webhook and optional Threads. Pull delivery also exists separately. | Add a WordPress adapter for existing content, with conflict handling. Retain custom webhooks; verify receiver support for existing-page updates independently of new-article delivery. |
| `AskAssistants` / `VisibilityReport` | Sample mentions and citations; answers are stored as excerpts. | Keep the current reporting scope. Full answer storage and factual checks belong to a later milestone. |

### Data boundaries for the first implementation

Codex will propose the schema during implementation with the owner; the required concepts are:

- **Tracked page:** tenant, observed URL, canonical identity, page type, CMS reference, and tracking state. Link existing `ContentItem` records where relevant; do not duplicate the same public page under two identities.
- **Page snapshot:** the editable source and extracted text, retrieval time, source revision/hash, and the fields the adapter can safely edit. Public HTML alone is not necessarily a valid editable CMS source.
- **Business fact:** claim, source, confirmation status, owner confirmation time, and freshness. Keep evidence separate from generated content and editing snapshots.
- **Opportunity:** target page, diagnosed issue, evidence window, confidence, effort band, proposed action, dismissal reason, and lifecycle state.
- **Change proposal:** pinned source snapshot, exact field/section changes, supporting facts, reviewer, and approved revision. Editing an approved proposal invalidates its approval.
- **Publication record:** destination object, before/after revisions, attempted change, actual outcome, verification, and recovery information. Reuse existing delivery/retry infrastructure where appropriate.
- **Measurement record:** baseline and follow-up windows, search readings, the authoritative purchase event/source, recorded purchase count/value and currency, completeness, and known confounders. Keep new-customer status unknown where it cannot be established.

Suggested opportunity lifecycle: identified → proposed → approved → publishing → verified → observing → reviewed, with dismissed, failed, and conflict states. Approval alone must never mark a page as changed.

**Preserve the evidence rule:** importing an existing article for editing does not make its statements confirmed business facts. Generated prose must not become evidence simply because it has been published and crawled again.

## 5. Delivery milestones

Implementation status and requirement-level evidence are tracked in [implementation and verification](seo-geo-implementation.md). Delivery is shared by the owner and Codex. The owner supplies business context/access, helps with manual review and publication, and leads customer recruitment. Codex carries out engineering and verification; each delivery phase receives an independent subagent critique. No external team is assumed.

| Milestone | Priority / horizon | Accountable role | Depends on | Exit condition |
|---|---|---|---|---|
| F0 — Prepare the the private pilot pilot | P0 / Now | Owner + Codex | Business/site access | Initial pages, purchase source, custom publication path, and baseline plan identified. |
| F1 — Simplify the offer and establish tracking | P0 / Next | Codex, with owner verification | F0 | Purchase tracking verified; existing pages, sources, and baseline data usable. |
| F2 — Deliver one useful page change with assisted publishing | P0 / Next | Owner + Codex | F1 | A the private pilot change is approved, live, verified, and being measured. |
| F3 — Automate WordPress publication | P0 for WordPress self-serve / Next | Codex, with owner review | F2 + representative WordPress installation | Read, preview, approve, update, verify, and recover work on supported pages. |
| F4 — Validate with external businesses and launch | P0 for launch / Next | Owner + Codex | Repeated F2 cycles; F3 for WordPress self-serve | Paid external adoption, quality, support effort, and renewal meet the gates. |
| F5 — Add GEO accuracy and fact maintenance | Included / After core workflow | Owner + Codex | Page, evidence, proposal, and publication foundations | Both capabilities delivered and verified; customer value assessed separately. |

### F0 — Prepare the the private pilot pilot

- [ ] Inspect `the pilot site` and its relevant project configuration. Select roughly 5–20 initial service/pricing/supporting article pages and one priority locale; record the actual market and language.
- [ ] Establish where a purchase becomes authoritative in the booking/payment process. Identify the source of sale status, transaction identity, actual value/currency, and, if available, first-time-customer status.
- [ ] Inspect available Search Console/GA4 access and existing tracking. The owner's report that purchases are not tracked is the starting assumption; do not invent historical purchase attribution.
- [ ] Verify the custom site's existing receiver and what it supports: new articles, article updates, and/or edits to existing service pages. Specify an assisted handoff for unsupported updates. Existing webhooks do not automatically provide an editable page snapshot or conflict checking.
- [ ] Prepare one concrete recommendation manually using the existing engine; record why it is worth doing and which actual business facts support it.
- [ ] Identify a representative WordPress test site/page set for F3. WordPress is the first native integration, regardless of the private pilot's implementation.
- [ ] Record the baseline plan, tracking start date, candidate unchanged comparison pages, and known commercial changes.

**Gate:** begin implementation once the selected pages, purchase source, required access, and publishing route are understood. Missing access blocks only the dependent integration work. An external sales cohort is not required to start F1/F2. Demand and price validation move to F4.

### F1 — Narrow the experience and establish a reliable baseline

**Product and social changes**

- [ ] Make Overview, Plan, Content, and Performance the main navigation for the focused offer. Put audits, detailed AI visibility, delivery logs, and setup behind relevant pages/settings.
- [ ] Remove social promises and broad “organic growth team” language from the new landing page, onboarding, demonstration content, and future plan comparison.
- [ ] Align the trial copy with the actual card/checkout sequence. Explain that the trial demonstrates proposed work and usability, not organic-growth results.
- [ ] Remove social from the active experience, including navigation, onboarding, assistant tools, direct routes, manual generation, and plan inclusions. Nobody currently depends on it; an ongoing internal social product is not required.
- [ ] Stop queued social work, repurposing in `engine:tick`, scheduled listeners/planners/drafters, and social delivery selection. Handle retries and partially running jobs so stopping the product stops new social spend.
- [ ] Preserve historical social data and shared assets/components. A historical read/export path is sufficient; do not build a new access tier or delete tables just to simplify the menu. Keep historical billing records intact.

**Page and measurement changes**

- [ ] Import selected existing pages with a bounded crawl and store editable snapshots. Preserve URL/canonical/locale identity and avoid duplicate tracking.
- [ ] Show the editable fields and supported page types for the chosen CMS. Unsupported layouts receive an explicit assisted-handoff path.
- [ ] Capture business facts with sources and owner confirmation. Ask for missing facts instead of generating replacements.
- [ ] Ingest settled Search Console page data and relevant query-to-page data for selected pages, with bounded pagination, data freshness, and incomplete-data states.
- [ ] Retrieve an initial 28-day search baseline and a preceding comparable window where available. Purchase measurement begins prospectively after tracking is verified; missing historical tracking is unavailable, not zero. Record known campaigns, major site changes, and seasonality concerns.
- [ ] Implement and validate actual purchase measurement on the private pilot from the source identified in F0. Use the standard GA4 `purchase` event where applicable, with a stable transaction ID, actual value/currency, and the service purchased. Deduplicate repeated confirmations and distinguish purchases from quote requests, unpaid bookings, and button clicks.
- [ ] Reconcile test transactions with the booking/payment record; define how cancellations/refunds and first-time versus returning customers are recorded. Use an explicitly labelled manual reconciliation if the sale completes outside the measurable website journey. Do not fabricate landing-page attribution for unmatched sales.
- [ ] Check GA4 dimension/metric compatibility and the landing-page attribution available from the real flow. Where source/session matching is unavailable, show purchase totals with unattributed sales separated. Document tracking gaps and the date reliable collection began.
- [ ] Preserve a meaningful read-only experience when Google or the CMS is disconnected. Missing data must display as unavailable rather than zero.
- [ ] Do not infer “not indexed” from zero impressions. Treat crawlability, observed search impressions, and verified indexing as separate facts.

**Acceptance:** a selected page created before Avyo has a current snapshot, confirmed evidence, a visible search baseline, and an honest purchase-measurement status. Test purchase records reconcile without duplicate sales. Social actions and retries produce no unintended jobs or spend. Do not wait for a full 28-day purchase history to start F2; record the available baseline length and retain comparison pages where possible.

### F2 — Produce useful improvements and verify an assisted publication

- [ ] Start with three opportunity types: an existing page losing relevant search performance; an existing page with meaningful search exposure and an answer/intent gap; a service page missing a buyer-critical fact the owner can confirm.
- [ ] Rank with explainable factors: commercial relevance, observed search evidence, content gap, confidence, and implementation effort. Do not invent revenue forecasts or a precise expected uplift.
- [ ] Show no more than five recommendations and allow “nothing worth changing” as a valid result. Low-volume sites receive an evidence-led review with lower confidence.
- [ ] Check whether a suitable page already exists before proposing a new one. Basic overlap detection is sufficient; automatic consolidation and redirects are deferred.
- [ ] Create a bounded proposal covering supported title, description, text-section, or internal-link changes. Preserve working content and provide an explicit reason for each change.
- [ ] Display before/after text, sources, missing facts, and the measurement to follow. Pin the source revision and evidence used.
- [ ] Support accept, revise, and dismiss. Record review time and substantive corrections; a materially altered proposal must be reviewed again.
- [ ] During the pilot, provide a precise handoff for the owner/operator to apply the change. Record who applied it and when; export is not publication.
- [ ] Fetch the live page after publication and verify the supported changes. Start follow-up measurement from the verified date.

**Acceptance:** an owner completes the journey for an existing service page without creating a new article or setting up a social channel. A second pilot example covers an existing article. Both retain their public URLs and have a verified change record.

### F3 — Automate WordPress safely

WordPress is the first native CMS integration. Use a representative WordPress installation with service pages and posts; success on the private pilot's custom receiver does not validate WordPress. Start with supported core content structures and explicitly inspect SEO-plugin metadata and page-builder compatibility. Do not build a generic page-builder editor.

Keep webhooks as the custom-site transport. Expand their receiver contract only where required for the same approved-update workflow, preserving compatibility with existing new-article delivery. A webhook receiver that cannot read/check the original revision keeps an assisted publication path until that capability exists.

- [ ] Identify the exact CMS object and obtain its editable source and revision before proposing a write.
- [ ] Support a documented subset of pages/posts and metadata fields. Resolve ownership by SEO plugins and editors during the feasibility check.
- [ ] Compare the current CMS revision with the reviewed snapshot immediately before publication. A conflict creates a new review requirement rather than overwriting an external edit.
- [ ] Separate approval from publish permission. Apply only the approved fields; retain layouts, unrelated metadata, forms, URLs, and existing content outside those changes.
- [ ] Reuse delivery identity, retry, metering, and tenant controls. Ambiguous outcomes must be reconciled before retrying; repeated requests cannot duplicate content or changes.
- [ ] Save the original revision and provide recovery for a failed or unwanted change. Recovery must also detect subsequent external edits.
- [ ] Verify the public result and applicable metadata. A successful API response is insufficient to declare verification complete.
- [ ] Offer a clear assisted fallback for unsupported content, expired credentials, and conflicts.

**Acceptance:** read → propose → approve → publish → verify works on representative real service pages and articles. Conflict, retry, disconnected account, unsupported layout, and recovery cases have been exercised. No change to an existing page is published solely because it was generated.

### F4 — Measure purchases, validate external demand, and release

- [ ] After the internal cycle works, recruit up to five external local businesses, initially favouring service businesses with comparable pages and booking/sales flows. Record alternatives, purchase-tracking readiness, and the reason each joins or declines.
- [ ] Present a concrete page proposal and the provisional US$89 monthly offer, with explicit scope and actual checkout currency. Aim for at least three paid commitments before broadening beyond the initial supported workflow. Do not count the private pilot as external demand or charge it to manufacture pilot revenue.
- [ ] Show each changed page's baseline, verified date, elapsed observation window, and follow-up readings. Begin reviews at roughly 14 and 28 days, extending when data is sparse.
- [ ] Report actual purchase count and value/currency, with new-customer purchases separated where known. Show requests, bookings, and enquiries only as intermediate signals. Separate attributed, unattributed, and manually reconciled purchases; observed purchases do not establish incremental sales caused by Avyo.
- [ ] Use the same sale definition, transaction handling, and landing-page definition across the comparison. Missing consent, attribution loss, low volume, outages, refunds, and changed tracking remain visible limitations.
- [ ] Compare with suitable unchanged pages and wider site trends where possible. Report associations; do not attribute every before/after change to Avyo.
- [ ] Review AI visibility using stable prompts and recorded model/market/date context. Show mentions and citations separately. Sampled answers are not a census of real customers' AI activity.
- [ ] Re-rank future work from measured evidence and explicit owner feedback. A dip must not automatically trigger another full rewrite.
- [ ] Test the provisional US$89 per-site package with a clear allowance for reviewed page improvements. Measure manual assistance separately from provider costs; revise scope or price if the cost of support makes a small discount unsustainable.
- [ ] Version any new plan and quota semantics. Define what consumes an improvement allowance, what retries/revisions include, and how a new article is counted before selling it. Rejected proposals and technical retries must not create surprise charges.
- [ ] Publish the supported page types, integrations, trial terms, and scope clearly. Preserve existing purchased access and content.

**Acceptance:** a customer can see what changed, whether it is live, what has been observed since, and the next proposed action. Self-serve launch additionally requires F3; an explicitly assisted pilot can operate earlier.

### F5 — GEO accuracy and fact maintenance

**Implementation scope update:** the owner's subsequent instruction is to implement all of this without leaving work for the future. Both extensions below are therefore included in the current delivery sequence after the core workflow. The original demand hypotheses remain validation questions, not reasons to leave an implementation unfinished.

Deliver both extensions after the core workflow, then validate their value with customers:

| Extension | Minimum useful behaviour | Customer-value hypothesis to validate |
|---|---|---|
| AI-answer accuracy | Store full sampled answers; compare factual claims with confirmed business facts; show exact discrepancies and cited sources; propose owned-page corrections or an external handoff; recheck. | Pilot customers encounter material inaccuracies and will pay for ongoing detection/correction assistance. |
| Fact maintenance | Change a confirmed fact; identify statements that depend on it across tracked pages; prepare reviewed edits. | Repeated stale-fact problems impose measurable review or business cost. |

Neither extension can promise that editing a page will change an assistant's answer. A citation is evidence to inspect, not proof of the origin of an error. External correction messages require an explicit customer action.

## 6. Pilot scorecard and release decision

Run the scorecard in two stages. Stage A is the single internal the private pilot pilot: validate purchase tracking, a useful live page improvement, and repeatable operation. Stage B is an external cohort of up to five businesses: validate willingness to pay and retention. The thresholds below are proposed decision rules, not forecasts. Report raw counts and the relevant denominator: businesses approached for paid commitment, enrolled businesses for activation, and businesses eligible to renew for renewal. Exclude the private pilot from all external-demand measures.

| Measure | Definition | Proposed threshold / review |
|---|---|---|
| Internal readiness | the private pilot has reconciled purchase tracking and one useful, verified page improvement | Required before external launch; does not prove market demand. |
| Paid commitment | External businesses accepting the documented monthly offer after a concrete proposal | Target at least 3 of 5 approached for the first cohort in F4; not a gate on starting the internal build. |
| Activation | Connected external business reaches one approved, live, verified page improvement | At least 80% of enrolled businesses within seven days of completing required setup; report counts. |
| Review quality | Proposals usable without changes to facts, intent, or page structure; minor wording edits are allowed | At least 80%, reporting numerator/denominator and rejected work. |
| Owner effort | Active review time for that week's proposals | Median under 15 minutes per business per week. |
| Publication integrity | Verified intended changes with unrelated fields preserved | Every pilot publication accounted for; no unresolved destructive overwrite or duplicate write. |
| Repeat value | External business completes four weekly review cycles and pays to continue | Target at least three paying renewals; report enrolled and renewed counts. A week can end with a justified decision to leave pages unchanged. |
| Delivery economics | Provider cost plus measured operator/support time per retained site | Record from the first pilot; set the sustainable price/margin threshold before launch. |
| Purchase outcomes | Reconciled purchase count/value and new-customer purchases where known; comparable page/search trends as context | Review over 8–12 weeks from reliable tracking or longer for sparse data; no invented historical baseline or uplift target. |
| AI representation | Stable sampled prompts, accurate facts, and observed citations | Supporting evidence; not the primary retention or revenue metric. |

If owners repeatedly reject changes, fix diagnosis and evidence quality. If changes are accepted but nobody renews, revisit the customer and paid promise. If publication support consumes the margin, narrow the CMS/page formats. Adding more social or GEO features is not the default response to any of these failures.

## 7. Implementation touchpoints and validation

| Work | Starting points |
|---|---|
| Positioning, navigation, first-run experience | `app/resources/js/pages/marketing.tsx`, `app/resources/js/components/app-sidebar.tsx`, `app/resources/js/pages/home/`, `app/resources/js/pages/onboarding/` |
| Social boundaries and background work | `app/config/social.php`, `app/routes/web.php`, `app/routes/console.php`, `app/app/Console/Commands/EngineTickCommand.php`, assistant tools and social controllers |
| Page snapshots and evidence | `app/app/Models/SitePage.php`, `app/app/Support/Corpus/SiteLibrary.php`, `app/app/Models/ContentItem.php`, `app/app/Models/BrandBrief.php` |
| Opportunity diagnosis | `app/app/Feedback/GoogleSearchConsole.php`, `app/app/Pipelines/Steps/Feedback/`, `app/app/Pipelines/Steps/Planning/` |
| Proposal generation and review | `app/app/Pipelines/Steps/Generation/`, `app/resources/js/pages/content/`, `app/resources/js/pages/approvals/` |
| Publication | `app/app/Publishing/`, `app/app/Providers/AppServiceProvider.php`, `app/packages/engine-receiver/` |
| Outcome measurement | `app/app/Feedback/GoogleAnalytics.php`, `app/app/Models/ContentMetric.php`, `app/resources/js/pages/feedback/`; the private pilot booking/payment tracking requires a separate site-side change when implementation begins |
| Focused diagnostics | `app/app/Audit/`, `app/app/Visibility/`, `app/resources/js/pages/visibility/` |
| Packaging and preservation of entitlements | `app/config/billing.php`, `app/app/Billing/`, `app/resources/js/pages/billing/` |

Use additive migrations and preserve existing identifiers, locale groups, published URLs, billing history, and tenant isolation. Avoid a platform rewrite. Add only the data concepts required for the next usable milestone.

For implementation, follow `CLAUDE.md`: use the local Docker Compose stack, run applicable host checks and container tests, and verify changed user flows visibly. Tests with fake providers need representative live integration checks before claiming a CMS or analytics flow works in production.

High-value checks cover tenant isolation, page identity/deduplication, missing/partial measurements, separation of editing text from evidence, approval invalidation, external edit conflicts, retry reconciliation, publishing recovery, quota accounting, and social jobs remaining off for focused projects. Keep verification proportional to each change. This documentation-only update requires no application runtime tests.

## 8. Remaining implementation decisions

The audience, first site, WordPress priority, willingness to assist, purchase outcome, pricing approach, lack of social dependencies, and delivery team are resolved. The following are discovery/design tasks for the owner and Codex, not prerequisites for another broad product questionnaire.

| Decision | Accountable role | Default / resolution point |
|---|---|---|
| First pages, market, and measurement locale | Owner + Codex | the private pilot in Lisbon is the initial context; verify site configuration/search data and select one initial locale during F0. |
| WordPress content formats and metadata compatibility | Codex | Test core pages/posts and representative editor/SEO-plugin behaviour before F3 scope is committed. |
| Actual sale trigger and tracking integration | Owner + Codex | Inspect the private pilot's booking/payment process in F0; implement and reconcile purchase tracking in F1. |
| Custom existing-page update support | Codex | Verify the receiver, editable snapshots, and conflict handling; use an assisted handoff wherever the current webhook contract is insufficient. |
| Localised checkout currency, included work, and sustainable cost | Owner + Codex | US$89 is the planning reference; set the actual currency, allowance, and scope from internal delivery costs before selling in F4. |
| Exact table/schema design and adapter contract | Codex, with owner review | Resolve before the related milestone; preserve the data boundaries in section 4. |
| Delivery dates and capacity | Owner + Codex | No deadline/budget supplied; sequence milestones and estimate after F0. |
| How to package AI accuracy and fact maintenance | Owner + Codex | Both are included in implementation; use customer evidence to decide how they feature in the paid offer. |

## 9. Competitive context and evidence

Competitive feature research was reviewed on 14 September 2026; the price benchmark and pilot website were checked on 15 September 2026. Vendor documentation describes advertised capabilities, not independently tested quality. Absence from documentation is not proof of absence from the product.

- BabyLoveGrowth documents research, brand configuration, internal links, and planning informed by Search Console. These are baseline capabilities, not our differentiator. [Article quality](https://www.babylovegrowth.ai/docs/dashboard/article-quality), [settings](https://www.babylovegrowth.ai/docs/dashboard/settings).
- Its analytics include traffic, AI mentions, sentiment, competitors, and citation sources. Our proposed advantage must go beyond another visibility dashboard. [Analytics](https://www.babylovegrowth.ai/docs/dashboard/analytics).
- It supports editing generated articles and discusses refresh opportunities. Do not claim that it cannot update content. The unverified area is the depth of an existing-page improvement workflow tied to business outcomes. [Article management](https://www.babylovegrowth.ai/docs/dashboard/content-plan), [content update workflow](https://www.babylovegrowth.ai/en/blog/seo-content-update-workflow-for-boosted-organic-results).
- Its technical audit provides issue guidance and developer/agent handoffs. Avyo currently also stops at a fix plan. [Site health](https://www.babylovegrowth.ai/docs/dashboard/technical-audit).
- Native CMS breadth, hosted blogs, multilingual market research, and off-site distribution are areas where its documented offer exceeds Avyo's current implementation. Closing every gap would defeat the focus decision. [Integrations](https://www.babylovegrowth.ai/docs/integrations), [plans](https://www.babylovegrowth.ai/en/pricing).
- Existing-page optimisation is not an empty market: Surfer and Profound already address it. The bet is a narrower customer, supported commercial-page work, and a simpler complete workflow. [Surfer](https://docs.surferseo.com/en/articles/11394490-step-3-how-to-fix-your-existing-content), [Profound](https://www.tryprofound.com/features/agents/content-optimization).
- For Google AI Overviews and AI Mode, no special AI files or additional schema are required. Relevance, accessible content, and sound SEO remain the basis; avoid marketing speculative GEO scores as guaranteed growth. [Google Search Central](https://developers.google.com/search/docs/appearance/ai-features).
- Private pilot evidence is retained locally and is not included in this public repository. It establishes neither the state of purchase tracking nor the CMS configuration.
- GA4 supports purchase/refund measurement for products and services, with transaction and value/currency information. Adapt event collection to the actual sale lifecycle; the public site's booking call to action alone does not establish a purchase event. [Google Analytics purchase measurement](https://developers.google.com/analytics/devguides/collection/ga4/ecommerce).

Revisit competitive claims before public launch. The first proof should be a customer-approved page improvement, a dependable publication, and a paid renewal.
