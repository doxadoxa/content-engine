# Product design review — September 21, 2026

## Scope and verdict

An independent design critic reviewed Dashboard, Calendar, Content, Search performance, and AI visibility using fresh local pilot screens at 1440px and 390px, page text and element positions, and the relevant source. The findings were consolidated and checked against the captured screens by the primary agent.

The five destinations have clear roles and a consistent visual identity. The main issue is hierarchy and clarity about what the system will do next. The current account has 53 articles but no scheduled publications, incomplete publishing setup, historical AI results, and no connected Search Console measurements. Those states should be immediately understandable to a business manager.

Keep growth metrics and AI visibility prominent. Add a compact publishing status and one relevant next action close to those metrics; avoid turning the Dashboard back into a technical task list. This calls for focused improvements, not another redesign.

## Recommended order

| Priority | Change | Reason |
| --- | --- | --- |
| 1 | Fix overlapping article titles in Content | Confirmed rendering defect with the current inventory. |
| 2 | Clarify publishing readiness near Dashboard metrics | A manager should know whether content will publish automatically and when the next article is due. |
| 3 | Distinguish suggested Calendar dates from scheduled publications | A populated calendar currently gives a stronger impression of a committed schedule than the underlying state supports. |
| 4 | Bring AI question management and next-check setup near the summary | These controls currently follow all 19 historical questions. |
| 5 | Add Content title search and counts on status filters | The existing 53-article inventory is already difficult to navigate. |

The remaining recommendations below should follow as part of the same focused usability pass. They do not require changing the product's positioning, pricing, or core workflow.

## Dashboard

**High — publishing readiness is too far below the summary.** The page repeats the same 31.6% AI visibility total and includes a large disconnected Google panel before the content workflow. “Finish publishing setup” begins around 1,073px on desktop and 1,933px on mobile. Four articles needing review appear farther down.

Keep the outcome cards first, then show a compact publishing status: whether automatic publishing is active, the next scheduled article or “Nothing scheduled,” and a relevant action when intervention is necessary. Reduce repetition in the detailed AI panel and condense disconnected reporting panels. Keep deeper provider analysis available without letting it hide operational status.

**Moderate — setup copy is contradictory.** “Selected preference: automatic publishing” is immediately followed by “Choose how new articles should publish.” State the actual incomplete step and make the destination match it. For example, “Automatic publishing is selected; finish setup to activate it” is appropriate only if the underlying state supports that explanation.

## Calendar

**High — suggested dates resemble a committed publishing schedule.** The summary says “5 articles across 5 dates.” All five dates are September 1–16, before the September 21 capture, and every card explicitly says it is not scheduled to publish. The labels are truthful; the summary and filled grid create the confusing impression.

Lead with scheduled versus suggested counts, such as “0 scheduled · 5 suggested dates need scheduling.” Give tentative dates a distinct treatment and keep the required publishing setup action nearby. Past suggestions should be recognizable as suggestions that still need a decision, without silently moving dates or scheduling articles.

**Moderate — secondary metadata crowds out titles.** Narrow desktop cards truncate titles while displaying a query, article type, language count, and repeated scheduling explanation. Prioritize title, status, and timing; show secondary details when the article opens. Preserve the more readable mobile agenda.

**Minor, source-confirmed — status styles use outdated keys.** `StatePill` maps states such as `draft` and `generating`, while current callers pass publication states such as `needs_review`, `writing`, and `scheduled`. Several statuses therefore share the gray fallback. Update the styling to match the current states while retaining text labels and avoiding color-only distinctions.

## Content

**High, confirmed rendering defect — long titles overlap the Type column.** The article about choosing post-construction cleaning products and a longer renovation article visibly run into the adjacent column. The title cell inherits `whitespace-nowrap` from the shared table component. Let titles wrap within their own column, with predictable spacing and a readable link.

**Moderate — the inventory is hard to search and triage.** There are 53 articles. The first mobile page contains 25 cards before pagination and is 5,158px tall. Add a simple title search and counts to the existing status filters. A shorter mobile page size is also worth considering; preserve filters and page position when returning from an article.

**Moderate — readiness and timing are mixed together.** Many rows repeat “Choose a date” in both Publication and Status. Separate editorial readiness from publication timing. Make scheduling a clear action rather than duplicating an instruction in two data columns, and only label an article ready when its actual state supports that claim.

## Search performance

**Moderate — the disconnected state contains a full empty explorer.** A useful Search Console connection card is followed by search, page selection, sorting, “0 of 0 query and page matches,” another empty state, and measurement definitions. The second empty-state heading appears around 1,181px down the mobile page.

When no saved measurements exist, prioritize connecting Search Console and condense the empty explorer into a brief explanation of what will appear. Keep monitored pages accessible. Distinguish disconnected, connected-but-waiting, and recorded-zero states. If historical measurements exist after a connection is lost, retain them with their dates instead of hiding them.

The lack of Google measurements in this account is not a design defect. The recommendation concerns how that state is presented. The populated query and page explorer was not visually verified in this review.

## AI visibility

**High — future checks are buried below historical results.** “Manage future questions” starts around 4,280px on desktop and 6,743px on mobile, after all 19 questions. Provide a visible route to question management and next-check setup beside the summary, reflecting the real configuration and plan allowance. Show a manageable first set of historical rows with pagination or “Show more.” Keep exact question text visible and searchable.

**Moderate — the displayed outcomes lack inspectable answer evidence.** The historical rows show exact questions, provider outcomes, and dates, but no answer or excerpt drill-down. Where a saved excerpt exists, expose it. Where it does not, explicitly say the text is unavailable. The older-results response omits excerpt fields even though the record model supports nullable excerpts. This is an evidence-access gap, not evidence that the 31.6% result is wrong. Do not invent full answers or merge incompatible reporting methods.

**Minor — reporting internals dominate supporting copy.** Terms such as “earlier excerpt samples,” “current runs,” and “No full-answer check yet” describe implementation history. Lead with the observation date and what was measured; put methodological differences in an expandable explanation. Preserve the distinction between old and new checks. Compact provider summaries on mobile so the actual tracked questions appear sooner.

## Shared action hierarchy

“Create content” is currently the filled primary action, while “Plan my content” is secondary. Match prominence to the user's current stage: surface the specific incomplete publishing setup when it blocks the workflow; make planning prominent when the account is ready and needs a plan. Keep manual article creation readily available. Avoid repeating competing primary buttons across the header and body.

## Keep

- Five clear navigation destinations, with Calendar and Content easy to find.
- Growth and AI metrics with denominators, observation dates, and honest unavailable states.
- Exact tracked AI questions and the dedicated Google query/page view.
- The forest-and-cream visual design and consistent headings.
- Mobile Calendar agenda and Content cards. None of the ten captures has document-level horizontal overflow.
- Existing accessibility foundations: labelled filters, active-navigation semantics, text alongside status colors, and a data-table alternative for charts.

## Evidence and limits

Private pilot captures and JSON records are retained locally and are not included in this public repository.

This was a read-only review of the current local account. It did not test paid checks, generation, publishing, transactions, keyboard or screen-reader flows, or measured contrast. Populated Google results and newer full-answer AI reports were not present in the captured data. Suggestions about those states need verification during implementation.

No application code or account data was changed for this review.

## Implementation — September 21, 2026

The recommendations above were subsequently implemented after approval. Code was written by two GPT-5.6 Terra agents, with integration decisions, corrections, and acceptance review by the primary agent.

- **Dashboard:** growth numbers remain first. Publishing readiness and the next scheduled article now appear immediately below them. Removed the repeated AI total, reduced the disconnected Google panel, and clarified setup copy.
- **Calendar:** scheduled publications and suggested dates have separate counts and treatments. Past suggestions explicitly need a scheduling decision. Cards prioritize title, publication state, and time; colors now match current statuses. Published, paused, and blocked items retain their actual meaning.
- **Content:** titles wrap without overlapping columns. Added case-insensitive title search, matching status counts, 12-item pages, deterministic pagination, and preserved list context when returning from an article. Editorial state is separate from publication timing; scheduling links go directly to the existing controls. Searches find translated titles while retaining the unit's languages.
- **Search performance:** a disconnected account with no measurements gets a focused connection state with monitored pages still accessible. Retained history stays available in its original date window, including measured zeros. Connection, waiting, and recorded-zero states are distinct.
- **AI visibility:** question management is available near the summary. Five questions appear initially, with full-list search and incremental expansion that survives polling. Saved excerpts and safe citation links are inspectable where available. Historical and current checks remain separate; measurement details are expandable and action labels use plain language.
- **Shared actions:** publishing setup is prominent when incomplete; planning leads when ready; manual article creation remains available.

Validation covered 120 distinct focused tests in the local Docker environment, frontend lint/format/type checks, a production frontend build, PHP formatting and static analysis, and desktop/mobile browser checks. The read-only browser checks cover title search, pagination, return links, AI-question expansion and search, and retaining expanded questions and unsaved editor text through polling. Private pilot measurements are retained locally.

Implementation evidence is retained locally and is not included in this public repository. Live checks used an existing local account; automated tests cover additional data states with fake providers. No paid AI checks, publication actions, subscription changes, or background workers were started during this implementation.
