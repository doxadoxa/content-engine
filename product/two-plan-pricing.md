# Avyo — Starter and Growth

Updated 16 September 2026. Version 4 implements the owner's US$29 Starter and US$89 Growth monthly plans in the local application. Public cards, signup, setup, trial checkout and billing use this catalog. Update 24 September 2026: versions 1–3 were never sold and have been removed; the catalog is one unversioned list (preview, trial, Starter, Growth), and rows on an old plan were moved to Starter or Growth. Real checkout activation still requires Stripe credentials and the two matching price IDs; none are configured locally.

## Recommendation

Offer the same useful-content product at two publishing rhythms. Both plans research, write and publish automatically, with optional review first. Keep article quality, business grounding and website ownership consistent. A smaller business should be able to buy Starter without becoming its own SEO operator.

| | Starter | Growth |
|---|---|---|
| Monthly price, per website | **US$29** | **US$89** |
| New articles | **12 per billing month** — approximately three per week | **30 per billing month** — approximately daily |
| Best fit | Build a steady, useful presence | Cover more customer questions and keep improving |
| Research and calendar | Included | Included |
| Writing, fact checks, relevant links and article images | Same quality standard | Same quality standard |
| Automatic publication / optional review | Both included | Both included |
| WordPress / compatible custom website | Included | Included |
| Website / language / seats | 1 / 1 / 2 | 1 / 1 / 2 |
| Search performance and connected purchase results | Included | Included |
| Scheduled AI visibility observation | Monthly: 3 questions across 4 services | Weekly: 10 questions across 4 services |
| Existing-content improvements | Not included | Up to 4 first-accepted improvements per billing month |
| Support | Self-service product support | Self-service product support |

The AI observation limits are enforced per actual billing period. Scheduled checks, manual checks and rechecks share the same allowance; provider attempts that fail or time out still count. Services are the supported configured ChatGPT, Gemini, Claude and Perplexity answer APIs. Their observations are samples, not a census of customer answers. The interface should describe the difference as monthly versus weekly visibility checks, with detailed limits available alongside the plan.

No human-written articles, dedicated SEO manager, guaranteed traffic or customer acquisition is included. Those would change the economics and service model.

## The cadence decision

The implemented allowance is **12 articles per billing month**. Public copy should lead with that exact allowance; “about three per week” explains the rhythm. Twelve monthly articles do not promise three in every calendar week. The same rule applies to “30 per month,” which is not a promise to publish every day of a 31-day month.

This uses the saved fixed-monthly recommendation. It does not guarantee three publications in every calendar week. Choosing a slower publishing preference reduces automatic planning; it does not remove the unused manual article allowance.

## Why Growth must offer more than volume

At the proposed fixed allowances, Starter is **$2.42 per article** and Growth is **$2.97 per article**, before attributing value to any other capability. Growth costs about 3.07 times as much for 2.5 times the new articles. A customer buying only article volume would reasonably see Starter as the better deal.

Make the upgrade tangible: more new articles, weekly observation of a larger set of customer questions, and four improvements to existing content. Keep these connected to the manager's calendar and results. Do not turn Growth into a dashboard full of extra setup work. If customers do not value the extra work, reconsider the package rather than assuming the price difference explains itself.

BabyLoveGrowth currently advertises a US$99/month Grow plan with 30 articles plus other services, including AI prompt tracking and backlinks. Avyo's $89 tier is $10 below that advertised monthly price, but the packages are not equivalent. The $29 plan provides a lower-commitment starting point. Source checked 15 September 2026: [official BabyLoveGrowth pricing](https://www.babylovegrowth.ai/en/pricing).

## Cost validation before selling the new allowance

Earlier internal records estimated about $0.16 per article under an older workflow. That figure is not sufficient to price the restored workflow with its current images, grounding, retries and support. The recent $0.110590 provider experiment covered four short answers and one synthetic fact check; it did not measure full article production.

Measure complete article batches through the current pipeline, including research/planning allocation, images, factual checks, revisions, failed calls and delivery. Include the promised recurring AI observations and existing-content improvements. Record actual support time separately. No new paid validation is authorized by writing this proposal.

As a provisional business target, keep provider costs within 20% of plan revenue: $5.80 for Starter and $17.80 for Growth. This is a proposed target, not an observed margin or an automatic cutoff that may silently prevent delivery. Payment fees, hosting and support still need room. If observed costs cannot fit, adjust the package before sale; retain the agreed quality standard and make usage boundaries clear.

## Implementation requirements

1. **Version the offer.** Introduce a new catalog version with separate Starter and Growth keys and separate USD monthly Stripe prices. Preserve subscriptions on versions 1–3. Do not revive the old “small” package: it belongs to a different historical offer and currency arrangement.
2. **Carry the selected plan through the full journey.** Landing card → account/project setup → trial → checkout must retain the same plan, price and limits. Clearly identify the plan after the three-day trial. Keep the current payment-method requirement and three trial articles unless explicitly changed.
3. **Respect the actual billing period.** Plan dates across calendar and renewal boundaries using the customer's remaining allowance. Avoid a late-month signup losing most of its paid allowance, two calendar plans exceeding one billing allowance, or backfilling missed dates in a burst. The existing calendar-month planning window needs explicit review for both rhythms.
4. **Keep schedule control in both plans.** Automatic publication remains the default after website setup; review first, pause, reschedule and revisions remain available. First approval consumes one article unit. Existing retry/revision identity rules continue to prevent double charging. Changes of plan must not silently opt historical drafts into publication.
5. **Enforce the AI observation package.** Starter gets 12 scheduled answers per billing period; Growth supports up to five weekly batches of 40 answers, or 200. Persist question sets, period usage and dispatch identities. Manual reruns/rechecks must disclose and respect the same allowance; timeouts and failed paid attempts retain spend and cannot be retried invisibly. A plan's cost ceiling alone is not a substitute for these defined limits.
6. **Enforce supporting work.** Starter has no new page-improvement allowance; Growth retains four first acceptances. Historical approved work and saved results remain readable. An upgrade adds only the incremental entitlement for the same period; no resetting already used quota. A downgrade takes effect at renewal, with affected future schedules shown to the manager before confirmation.
7. **Update every offer surface together.** Landing, trial, onboarding, checkout, billing, allowance messages and admin reports must agree. Keep the public card short: price, article rhythm, publishing, visibility frequency and Growth's improvement allowance. Put accounting details behind a disclosure.
8. **Verify the change as one release.** Cover both checkouts and trial conversions, duplicate requests, 28/29/30/31-day periods, signup near month end, selected timezones, allowance exhaustion, upgrade/downgrade behavior, failed provider attempts and unchanged historical subscriptions. Review the full journey in the local browser before activating real prices.

## Landing-page direction implemented alongside this proposal

The public landing now leads with an interactive illustrative calendar, followed by an illustrated article and a visual explanation of search/AI discovery. Repeated feature paragraphs have been replaced with shorter copy, wider gaps and more separation between sections. The calendar's mode/topic controls affect only the example. No fabricated customer result, testimonial or growth chart is shown.

Pricing still comes from the actual billing catalog. The planned $29 tier should appear publicly only when a matching purchasable plan exists. The current $89 card has been simplified, with detailed allowances in an expandable section.

Landing verification completed locally: the calendar preview switches between automatic/review-first and selected article states; product navigation and the plan-detail disclosure work. Browser rendering passed at 390, 768 and 1,440px with no horizontal page overflow, and mobile/desktop screenshots were inspected. The existing public landing suite passed **3 tests / 42 assertions**. Scoped ESLint/Prettier, full TypeScript and the production build passed. No billing behavior or real subscription was changed.

## Validation and evaluation

Before activation, the selected price and allowance must match across every purchase screen, scheduling must remain within the agreed package, and the UI must clearly distinguish a smaller publishing rhythm from lower article quality.

After launch, record plan selection, activation, first successful publication, support minutes, actual provider costs, renewal and stated upgrade/decline reasons. Review after one full paid billing period; judge retention after actual renewals. The proposed price and feature split remain hypotheses until there is customer evidence. No results are inferred from synthetic tests or the internal pilot.


## Implemented behavior — 16 September 2026

- A versioned plan choice survives registration and is saved on the website setup draft. Another tab's selection cannot silently change that draft. A saved older offer remains readable but must be replaced with a reviewed current choice before a new checkout.
- The trial demonstrates three articles, one content plan and one AI batch of three questions across four services. The recurring amount is the selected paid plan's price. Checkout refuses a configured Stripe price with the wrong currency, amount or monthly interval.
- Article planning uses the actual confirmed subscription period and business timezone. Twelve or thirty slots fit short and long months, without treating calendar boundaries as new quota. Trial-to-paid conversion in the same calendar month has separate identity. Past dates are skipped rather than backfilled in a burst. An overdue automatic publication that has not been attempted needs rescheduling; uncertain delivery receipts retain their original retry identity.
- Upgrades retain consumed units and add the difference to the current allowance. Default pacing follows the upgraded plan; a deliberately slower preference remains. Lower allowances begin at renewal. Billing shows future active, paused, blocked and dispatching articles before explicit downgrade acknowledgement; approved work remains publishable while unapproved work shares the new cap. A pending downgrade can be canceled.
- Stripe schedule creation and phase updates use durable identities. Lost creation responses replay the same request; canceling and then scheduling again uses a new identity. The existing subscription is changed rather than opening another subscription for an existing payer.
- AI usage is reserved before dispatch and records paid attempts durably. Preflight refusals release a reservation; failures after transport may have started retain it. Old-period queued work cannot spend the new period's allowance. Starter runs once per confirmed billing period; Growth runs the current weekly slot, up to five slots, without catching up missed weeks. Changing tiers versions the question set and preserves prior observations.
- Paid-plan cost safeguards are deliberately generous emergency limits ($25 Starter / $75 Growth), distinct from the provisional $5.80 / $17.80 margin targets above. They are not calibrated delivery-cost evidence; cost refusals remain visible. The trial retains its $5 safeguard.

## Checkout activation

Set `STRIPE_KEY`, `STRIPE_SECRET` and webhook configuration for the intended environment. Create two separate active recurring **monthly USD** prices: Starter **2900 cents**, Growth **8900 cents**. Set `STRIPE_PRICE_SMALL` (Starter) and `STRIPE_PRICE_MEDIUM` (Growth) to them — the names the deployments already had. Checkout verifies each price's amount, currency and monthly interval, so an old price at a different amount or currency is refused rather than charged. Test-mode checkout, trial conversion and webhook reconciliation should be exercised with these configured IDs before enabling real customer charges. This implementation used fake payment transport, made no real charge and created no Stripe product or price.

Apply the additive migrations and deploy the matching frontend/backend together. Keep the supported four AI services configured. The existing scheduler includes the hourly `visibility:scheduled` sweep; article pacing remains part of `engine:tick`. Local workers stay stopped, so this work does not publish customer content or authorize ongoing paid generation.


## Verification record

16 September 2026, local Docker runtime and fake external providers:

- Billing, price selection, trial conversion, Stripe schedule transport, legacy billing and landing regressions: **163 passed / 762 assertions**. Final narrow webhook-release, two-plan and opportunity regressions: **59 passed / 314 assertions**.
- AI sampling, accuracy, legacy visibility and provider accounting: **81 passed / 386 assertions**.
- Final billing-period calendar suite: **18 passed / 330 assertions**, with legacy planning, scheduling and engine suites also passing in the recorded targeted runs.
- Complete application run: **2,290 passed and one unrelated nondeterministic opportunity fixture failed**. Equal title scores were ordered by random fixture URLs inside a five-result cap. The fixture now uses a deterministic sort position; its full 12-test class and the final 59-test regression run passed. The entire application suite was not repeated after this fixture adjustment and the final narrow webhook-release guard.
- Full PHPStan and Pint passed; final changed PHP files passed scoped checks. Frontend ESLint, Prettier, TypeScript and production build passed. `git diff --check` passed.
- Browser: Starter and Growth cards render; each CTA carries the correct price and article count into registration. Authenticated local billing shows both offers while the existing the private pilot project remains on its prior plan. Responsive rendering at **390 / 768 / 1440 px** shows two cards, both prices, working preview controls and no horizontal overflow.
- Applied the three additive migrations to the local development database. Background publishing workers remained stopped. No real Stripe or paid AI request was made. Live Stripe credentials and price IDs remain an activation dependency.
