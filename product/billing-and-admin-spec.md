# What it costs, what it sells for, and who can see both

Written 27 August 2026, before any billing code exists. The engine has run for
one project for three weeks and metered every model call it made, so the
pricing below is arithmetic on measured spend rather than a guess dressed as a
table.

---

## What the engine costs, measured

`Cleaning Point`, 6–27 August 2026, `weekly_target` 7, two locales, one social
presence. Every figure is `sum(pipeline_steps.cost_micros)` over that window:

| pipeline | runs | spend | per run |
|---|---|---|---|
| `content_studio` | 136 | $18.82 | $0.138 |
| `generation` | 94 | $8.74 | $0.093 |
| `research` | 19 | $0.20 | $0.010 |
| `planning` | 13 | $0.19 | $0.014 |
| `site_audit_fix_plan` | 5 | $0.05 | $0.009 |
| everything else | 15 | $0.02 | — |
| **total** | **282** | **$28.01** | |

$1.33 a day. Against what came out the other end — 58 approved articles and 40
social drafts — the two unit costs are:

- **an article, all in: ~$0.16** (research + planning + generation + repurpose,
  amortised across the ideas that never became articles)
- **a social post, all in: ~$0.47**, of which the picture is most of it —
  `atlascloud` billed $3.12 for 23 image calls, $0.136 each

The proportion is the thing worth staring at. **Pictures are three times the
price of prose.** Any plan that counts articles and lets social posts run free
has the economics backwards.

### The hole in the meter

`assistant_messages` records `input_tokens` and `output_tokens` and **no
`cost_micros`**. Chat is the one contour a customer can drive as hard as they
like — every turn can fan out into several tool calls, each a bill — and it is
currently invisible to `pipeline:cost`, to `MeteringController`, and therefore
to any ceiling built on them.

This is the first thing to fix. Billing needs exactly one place where all spend
lands, or the ceiling is a ceiling over part of the room.

---

## The shape: a subscription per project

A `Project` is the tenant. Cost accrues per project, value is delivered per
project, and `ProjectScope` already fails closed around one. Billing follows
that boundary and does not invent a new one.

- **Stripe Customer = the paying user.** One saved card.
- **Stripe Subscription, one per project**, created as a Cashier *named*
  subscription whose name is the project ULID. A user with four projects has
  one customer record and four subscriptions, manageable from one Billing
  Portal.
- **`projects.billing_user_id`** names which owner pays. The `project_user`
  pivot permits several owners; "the owner" is not currently a single row and
  billing cannot be ambiguous about who gets charged.

An Account or Workspace layer above `Project` was considered and rejected for
now. It buys pooled quota across projects, which nobody has asked for, at the
cost of migrating every project under a new parent and reworking membership.
Per-project subscriptions do not foreclose it: an account would later own the
Stripe customer instead of the user, and the subscription-per-project shape
survives unchanged.

---

## The plans

Limits are per project, per calendar month, and reset on the subscription's
period boundary rather than on the 1st — a customer who subscribed on the 20th
should not get a fortnight's quota for a month's money.

| | **Small** | **Medium** | **Enterprise** |
|---|---|---|---|
| Articles | 10 | **30 — one a day** | custom |
| Social posts | 10 | 30 | custom |
| Locales | 1 | 3 | unlimited |
| Seats | 2 | 5 | unlimited |
| Assistant turns | 100 | 500 | custom |
| Site audit | monthly | weekly | weekly + on demand |
| Publishing channels | 1 | unlimited | unlimited |
| Measured COGS | ~$6.30 | ~$19 | — |
| **Price** | **€29** | **€99** | **from €399, invoiced** |

**Medium is a daily article, because the market has already priced that.**
BabyLoveGrowth ships an article a day at $99 and a plan asking the same money
for twelve a month is not a plan anybody compares favourably. The measured cost
says we can meet it without thinking hard about it: thirty articles is $4.80 of
model spend, and the social half of the same plan costs three times as much.

It is also what the engine already does. `weekly_target` on the one live
project is **7** — a daily cadence, chosen before anybody was billing for it.
A twelve-a-month plan would have been a limit *below* the product's own
default, which is the sort of number that only looks reasonable in a
spreadsheet.

Gross margin is ~78% at Small and ~82% at Medium. That leaves room for the
model prices in `config/models.php` to move against us by a factor of four
before a plan stops paying for itself — and note which way the exposure runs:
at both tiers the pictures cost more than the prose, so the plan that looks
like an article subscription is really a social-media subscription with
articles attached.

Competing on article volume is table stakes and is not where this product
wins. A tool that writes an article a day does not also plan the month, cut the
social derivatives, verify the publishing connection, audit the site, and feed
what ranked back into what gets written next. The article count exists so the
comparison is not lost before the argument starts.

Enterprise is not self-serve. It is a subscription an administrator creates
against a custom Stripe price, with limits stored on the project rather than
read from the plan table. The panel below is where that happens.

Plans live in `config/billing.php`, versioned the way `config/models.php`
versions its price list: re-pricing publishes a new version and never edits a
published one, so a project keeps the entitlements it was sold.

---

## Two layers of limit

**The unit quota is the product.** Articles, posts, locales, seats, assistant
turns — things a customer can count and recognise, shown as "7 of 12 articles
this period" rather than as a number of tokens.

**The cost ceiling is the fuse.** Every plan also carries a `cost_micros`
ceiling per period, at roughly 3× its measured COGS. It is invisible in the
interface and exists for one case: a bug, a retry storm, or a creative customer
turning one project into a month of margin. Crossing it stops new spend and
raises an alert in the panel; it does not stop publishing, and it does not
cancel anything.

Two layers because they fail differently. The unit quota is what the customer
agreed to. The ceiling is what protects us from the units costing more than we
measured — a longer article, a redraw loop, a model price rise between the day
a plan was priced and the day it is used.

**Quota is consumed on approval, not on generation.** The engine drafts eight
social posts to keep one; charging a customer for the seven it threw away would
make the number on the screen meaningless. The spend of those seven is real and
lands against the cost ceiling instead, which is exactly the division of labour
the two layers exist for.

### The article limit clamps a dial that already exists

`projects.weekly_target` is what the tick reads to decide how much to write. A
plan's article allowance is therefore not only a counter to check — it is a
ceiling on that field: Small clamps `weekly_target` to 2, Medium to 7.

This is a better shape than counting alone. A counter stops a project dead on
the 22nd of the month, which reads as a broken engine rather than as a plan
boundary; a clamped `weekly_target` makes the engine pace itself so the month
comes out even and the limit is never felt. The counter stays as the backstop
for the paths that bypass the tick — the Studio's buttons, an operator writing
an article by hand — but on the contour that produces almost all the volume,
the limit is a setting rather than a wall.

The clamp is applied on read, not written into the row. An operator who set
`weekly_target` to 14 and then downgraded should get 14 back when they upgrade
again, not a number their plan quietly overwrote.

---

## The trial

Three days, no card, one project.

A card wall in front of a product whose whole argument is "it runs itself" asks
for trust before it has earned any. The cost of that decision is real and
bounded: a trial is capped three ways.

- **Time**: 3 days from project launch, not from registration. The clock starts
  when the engine does.
- **Units**: 3 articles, 5 social posts, 1 site audit, 1 monthly plan, 20
  assistant turns. Three articles in three days rather than two, deliberately:
  what Medium sells is a daily article, and a trial that delivers fewer than
  one a day demonstrates something other than the thing being sold.
- **Money**: a hard `cost_micros` ceiling of $5. Measured, that trial costs us
  ~$2.83, so the ceiling is headroom rather than a limit anybody reaches.

A hundred trials a month is $283 at the measured rate and $500 at the ceiling.
That is a marketing budget with a known worst case, which is the property that
makes a no-card trial defensible.

**The trial publishes.** Draft-only would be cheaper and would hide the one
thing that proves the engine works — content arriving on the customer's own
site without them doing it. Publishing is the demonstration.

Abuse controls: verified email required before launch, one active trial per
email and per verified domain, and the existing throttles on the routes that
spend. A project whose website URL is already on another account's project does
not get a second trial.

---

## Where the gates go

Every gate is an existing seam. Nothing here needs a new choke point.

- **`EngineTickCommand::projects()`** — the autonomous engine, filtered today by
  `status = active`. One more condition beside it and the whole scheduled
  contour respects entitlement. This is the single most important gate: it is
  where unattended spend comes from.
- **A `project.entitled` middleware** on the routes a person can press to spend
  money: `studio.propose`, `studio.generate`, `studio.ideas.*`,
  `studio.image.revise`, `content.articles.store`, `content.plan`,
  `audit.recheck`, `audit.fix-plan`, `brief.palette`, `social.posts.edit`,
  `assistant.store`, `assistant.reply`. They already carry throttles; this sits
  beside them.
- **`publish:approved` is never gated.** Content a customer approved and paid
  for goes out, through dunning, through cancellation, through the ceiling.
  Holding approved work hostage to a failed card is the kind of thing that
  turns a billing problem into a support incident and a refund.
- **Reading is never gated.** An expired project's articles, briefs, metrics and
  audit stay readable forever. `ProjectStatus::Paused` already means exactly
  this and is reused rather than duplicated.

The state a project ends in when nobody pays is `Paused`, plus a paywall screen
on the surfaces that create work. Not deleted, not hidden, not degraded.

### Dunning

`past_due` starts a 7-day grace: generation stops, publishing and reading
continue, the banner says what happened and links to the portal. At day 7 the
project pauses. Stripe's own retry schedule drives the transitions; we react to
webhooks rather than running our own clock.

---

## Stripe

**Cashier** (`laravel/cashier`), not a hand-rolled integration. It brings the
`subscriptions` and `subscription_items` tables, named subscriptions, webhook
handling and portal redirects — all of which we would otherwise write, badly,
and none of which is where this product's difficulty lies.

**Hosted Checkout and the Billing Portal**, not Stripe Elements. No card data
touches this application, tax and promotion codes come free, and plan changes,
card updates and cancellation are Stripe's problem rather than six screens of
ours.

**Webhooks are idempotent by event id.** A `stripe_events` table records every
processed event and a replay is a no-op. This repository already holds the line
on this in `WebhookDelivery` for outbound deliveries; inbound gets the same
treatment.

**A local projection for entitlement.** `project_subscriptions` — one row per
project, carrying plan key, status, `trial_ends_at`, `current_period_end`,
`grace_ends_at`, and the Stripe subscription id. Every request that asks "may
this project spend" reads one indexed row, never Stripe's API. Webhooks keep it
current; a reconciliation command repairs it when a webhook is missed, because
one will be.

`project_usage_periods` holds the counters — project, period start, metric,
used — incremented at the consumption points. Derived truth (counting
`content_items` and summing `cost_micros`) is the reconciler, not the enforcer:
enforcement needs an atomic increment and a race-free check, and a query over
two tables gives neither.

---

## The admin panel

There is no global administrative surface today. There is a `viewHorizon` gate
over an email allow-list in the environment, which is a bootstrap mechanism
rather than a permission model.

- **`users.is_admin`**, a real column, with the env allow-list kept only to
  bootstrap the first one. `viewHorizon` moves to read it.
- **`routes/admin.php`**, behind `auth` + an `admin` middleware, pages under
  `resources/js/pages/admin/`.
- **Every read opts into `acrossProjects()`.** `ProjectScope` fails closed, so
  a forgotten opt-in shows an empty table rather than another tenant's data —
  the failure mode is a visible bug, not a leak.

Five screens:

1. **Overview** — MRR, active/trialing/past-due counts, trial-to-paid
   conversion, spend against revenue for the month, and the projects nearest
   their cost ceiling.
2. **Users** — search, projects and roles, verification and last activity,
   which subscriptions they pay for.
3. **Projects** — status, plan, trial end, period spend, and **gross margin per
   project**. The metering data makes this nearly free to compute and it is the
   view no generic billing dashboard can give us: which customers cost more
   than they pay.
4. **Subscriptions** — Stripe state, renewals due, failures, and the drift
   between our projection and Stripe.
5. **Project detail** — the spend breakdown from `MeteringController` beside the
   plan, plus the operations: extend a trial, comp or change a plan, set custom
   Enterprise limits, pause or resume, resync from Stripe.

Every mutation is written to an audit log with the acting administrator, the
project, the before and after. Impersonation is deliberately **not** in this
phase: it is the one feature here that can act as a customer, and it should
arrive with its own audit trail and its own argument rather than as a line item
in a billing change.

---

## Registration

Public self-serve signup opens. This contradicts §10 of the original spec —
"there is no public registration: an account exists because someone created
it" — and it should, because the sentence describes an operator tool and this
change is what turns it into a service.

Fortify's `features` array gains `register()` and `emailVerification()`. The
marketing CTA lands on signup; signup lands on the onboarding wizard, which
already creates a project row from its first step and already attaches its
creator as owner. The trial is stamped at `onboarding.launch`, where the engine
actually starts.

`User::belongsToAnyProject()` keeps its meaning and its comment stops being
true about how accounts come to exist. It should be rewritten rather than
deleted.

---

## What deliberately does not change

- **Tenancy.** No new scope, no change to `ProjectScope`, `BelongsToProject`, or
  how queued work carries project context.
- **The pipelines.** Not one step class learns what a plan is. Entitlement is
  checked before work starts, never inside it, because a pipeline that can fail
  on billing halfway through is a pipeline that leaves half-written articles.
- **The metering screen.** `MeteringController` stays the operator's view of
  their own cost. The panel reads the same aggregates across tenants.
- **Publishing contracts.** Webhook and pull API behaviour is untouched. A
  paused project's already-delivered content stays delivered.

---

## Risks, and what to do about them

**The measured cost is one project's, over three weeks, with a developer
pressing buttons.** It is the best number available and it is not a cohort. The
plans should be priced from it and re-checked after ten paying projects, which
is why the price list is versioned.

**Pictures dominate social cost and their price is somebody else's decision.**
A provider price rise hits the Medium plan's margin harder than a model price
rise does. The cost ceiling absorbs the first month; the versioned price list
is how the second month is repriced.

**Assistant spend is unmetered today** and is the contour most exposed to a
determined customer. Nothing else in this document is safe to build until
`assistant_messages` carries `cost_micros` and feeds the same ceiling.

**A missed webhook silently entitles or disentitles a project.** The
reconciliation command is not optional and should run on the scheduler beside
`engine:tick`, not only as a manual repair.

**A no-card trial is a free API budget for anyone who wants one.** Email
verification, one trial per verified domain, and the $5 ceiling bound it. The
domain check matters most: it is the only one of the three that costs an abuser
something real.

---

## Phases

1. **Meter everything.** `cost_micros` on `assistant_messages`, folded into
   `MeteringController` and `pipeline:cost`. Nothing else starts first.
2. **Entitlement without Stripe.** `config/billing.php`, the two tables, the
   `Entitlements` service, the tick filter, the `project.entitled` middleware,
   the paywall and banner. Plans assigned by hand. The whole gating story,
   testable, with no payment provider in it.
3. **Stripe.** Cashier, products and prices, Checkout, the Portal, webhooks,
   the `stripe_events` idempotency table, the reconciler.
4. **Trial and registration.** Fortify registration, verification, the trial
   stamp at launch, the abuse checks.
5. **The panel.** `users.is_admin`, the admin routes and five screens, the
   audit log.
6. **Pricing on the marketing page**, last, when the numbers have survived
   contact with the first paying projects.

Phase 2 is the one that carries the risk and it is deliberately ahead of
Stripe: if entitlement is wrong, a payment provider only makes it wrong with
money attached.

---

## What changed while building it

The plan above survived contact largely intact. Six things did not, and each is
worth recording because each was discovered rather than anticipated.

**The trial moved into the versioned plan list.** It was written here as its
own block of config, which made it the one entitlement a re-pricing could still
change under somebody who was mid-trial — the exact thing versioning exists to
prevent, and the hardest to notice, because a trial lasts three days.

**Periods have to be rolled over by something.** A period is not only what the
unit counters are keyed to; it is the window the cost ceiling sums spend
across. Nothing advanced it, so a hand-assigned subscription would have
exhausted its quotas once and never reset, and accumulated spend for ever
against a fuse sized for a month. `billing:sweep` rolls them.

**The grandfathering window is a month, not a year.** The first instinct —
nothing renews these, so give them a long window — was wrong for the same
reason: a year-long period accumulates a year of spend against a one-month
fuse and trips it about ninety days in, which is the same outage the
grandfathering exists to prevent, merely postponed and made harder to read.

**Publishing had to honour an expired grace on the dates**, not on a column the
sweep flips. Waiting for the sweep meant a stopped scheduler kept publishing
for a customer whose dunning ended a week ago.

**Status has to be set through the transitions, never as a column.** Stripe
does not order its deliveries, so a subscription update carrying `past_due` can
land before the invoice event that explains it — and a row that reaches that
state without a grace deadline has nothing to expire.

**Quota exhaustion needed a prop of its own.** Running out of articles is not a
global refusal — the engine keeps cutting social posts — so it does not belong
in `refusal`. Without a separate `exhausted` list, running out was invisible
everywhere except a usage bar on a page nobody had a reason to open.

Two things in the plan were dropped as written. The `weekly_target` clamp is
applied by `Project::weeklyTarget()` rather than by the tick alone, because
four things read that dial. And `projects.billing_user_id` became
`project_subscriptions.billing_user_id`, since the payer belongs to the
subscription rather than to the project — a project can outlive the account
that paid for it.

## Success is measurable

- No project outside its entitlement starts scheduled work — assert on
  `engine:tick` with expired, past-due and ceiling-crossed projects.
- Every spend route refuses without entitlement and every read route does not.
- A replayed Stripe webhook changes nothing.
- The reconciler, run against a projection deliberately corrupted in each
  direction, restores it.
- Gross margin per project is visible in the panel and matches
  `pipeline:cost` for the same window.
- A trial cannot exceed 3 days, its unit caps, or $5, and cannot be started
  twice for one verified domain.
