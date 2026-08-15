# Studio, rethought

Written 14 August 2026, after reading Holo v2's docs and screens, Adomate's
positioning, and our own `resources/js/pages/studio/index.tsx` next to them.

This is not a redesign of a screen. It is an argument that the Studio is
organised around the wrong unit of work, and a plan for changing the unit.

---

## 1. What the references actually do

### Holo — three moves, and only three

Reading `docs.tryholo.ai/v2` end to end, Holo's product is smaller than its
screenshots suggest. It makes three moves and repeats them everywhere.

**Move one: the home screen is a chat bar over an activation checklist.**
A single input — *How can I help you today?* — with six intent chips (Create,
Analyze, Research, Brainstorm, Explore, Recommend), and underneath it one card
per surface: *Ads setup 1/5*, *Social media setup 0/4*. Each card is an ordered
list where done items are struck through, the current item carries the only
button on screen, and everything after it is **padlocked**. Review brand DNA ✓ →
Connect Meta Ads → Set your KPIs & strategy 🔒 → Create your first ad 🔒 →
Approve your creatives 🔒.

The checklist is the product's spine. It answers "what do I do next" before the
user has to ask, and it is why an empty account does not look broken.

**Move two: every surface has the same three tabs.** Socials, Ads and Emails
are the same screen with different nouns — **Overview / Create / Analytics**.

- *Overview* is a header of three numbers — completion %, *which week of the
  strategy you are in*, progress toward the one chosen KPI — over a kanban
  **Actions board**: To Do / In Progress / Done. To Do holds AI-recommended
  ideas, each with a title, a rationale, a suggested format and a publish date.
  Starting one moves it right. Publishing moves it right again.
- *Create* is four stacked shelves: **Post ideas** (six cards, each labelled by
  angle — *Save-worthy teardown*, *Before/after transformation*, *Community
  debate*, *POV*, *Founder tension*, *Creator proof* — with a hook headline, a
  one-paragraph execution note, and a content-type chip: Carousel / Single image
  / Text post / Reel); **Competitor ads** (a horizontal shelf of rivals' live
  ads, gated behind "Add competitors"); **Create new** (a rail of ~15 named
  creation methods: Content swipe, Video swipe, Image to video, Image carousel,
  Image variations, Video variations, URL to image…); and **Clone** (a masonry
  wall of real ads from your industry that it will reskin to your brand).
- *Analytics* is per-surface performance.

**Move three: strategy is one KPI and four weeks.** You pick exactly one of
*Grow followers*, *Grow reach*, *Grow engagement*, type a target, and get back a
cadence, a projected weekly growth curve, and four weekly objectives. You
confirm it. That confirmation is what every later recommendation claims to
ladder up to.

And the flow from an idea to a published post is a **linear four-step wizard**,
not a workspace: creative review (see the platform preview, swap the media) →
caption review (edit or replace) → summary (platform, type, timing) → schedule.
Then the card lands in Done.

Connection is thin and, per the user's own experience, the weakest part: Meta
OAuth for Facebook and Instagram, and the docs say nothing about the actual
requirements — an Instagram **Business or Creator** account, linked to a
Facebook **Page** you administer, an app with advanced access to
`instagram_content_publish`. A button, and a shrug when it fails. See §6.

### Adomate — one idea worth taking

Adomate is an ads tool, and only one thing about it is relevant here: **the raw
material comes from outside the brand.** It scrapes the Meta Ad Library,
competitors' live ads, Trustpilot and Amazon reviews, and turns those signals
into creatives. Its review surface is a **board where you like or skip**, and
what you liked feeds the next round.

Two things to steal: *the input is not only your own website*, and *the review
gesture is a preference signal, not a discard*.

Sources: [Adomate](https://www.adomate.com/) ·
[Product Hunt](https://www.producthunt.com/products/adomate) ·
[Holo docs](https://docs.tryholo.ai/llms.txt)

---

## 2. What is wrong with our Studio

Not opinions. Each of these is a property of `studio/index.tsx` and
`ContentStudioAssistant`.

**1. The unit of work is a month.** Every action on the screen is plan-scoped:
`propose`, `refine`, `accept`, `generate`. There is no way to make one post.
An operator who wants a post about the thing that happened this morning has to
either wait for next month's proposal or not use the Studio. This is the single
biggest gap against Holo, whose unit is a card.

**2. Nothing tells the operator what to do next.** The screen offers *Build this
month's plan*, then *Use this plan*, then *Generate next week*, then nothing. It
never says "these three posts are waiting for you today". `Today` and
`Approvals` exist as separate screens precisely because the Studio refused to
answer the question.

**3. The plan is unmeasurable, so "accepted" means nothing.** There is no goal,
no target, no cadence, no notion of week 2 of 4. `ContentPlan` carries a month,
a summary, a strategy blob and a version. Holo's "Week 2 of 4 · 34% of target"
is the cheapest legitimacy device in its whole product and we have no equivalent.

**4. The proportions are backwards.** The conversation gets `1.45fr`, every
working artifact gets `0.55fr` and two modal dialogs. Holo puts chat in a 60px
bar and gives the whole page to boards and grids. A conversation is how you
*change* the work; it is not the work.

**5. Drafting is week-batched, and the per-idea path is built but unreachable.**
`ContentStudioAction::GenerateIdea` exists, is locked per idea, and is dispatched
by the `generate_week` fan-out. There is **no HTTP route** that starts one.
`routes/web.php` exposes only `studio/plans/{plan}/generate`. Clicking one idea
and getting drafts for it is a controller method and a route — the engine
underneath is already correct.

**6. Review is four levels deep.** Aside → *Content map* dialog → calendar cell →
idea inspector → draft card → media controls. Holo's is four *forward* steps.
Ours is four levels of *nesting*, and the draft body renders in a
`max-h-52 overflow-y-auto` box — the post you are approving does not fit on
screen.

**7. There is one creation method.** Six art-direction fields go to an image
provider; the operator may redraw, ask for three, or upload a real photograph.
`social-media-studio.md` already names four treatments — real photograph, brand
overlay, composite, structured graphic — and only the first and a half are built.
Holo ships fifteen named methods and a clone wall. We do not need fifteen. We
need more than one and a half, and we need them *named*, because a named method
is a thing an operator can ask for.

**8. Nothing external ever enters the system.** Every idea is derived from
`site_analysis`, the Brand Brief and our own corpus. No competitor, no review, no
trend, no ad library. That is a closed loop that can only ever restate what the
website already says — which is exactly the "sounds like stock" complaint
`social-media-studio.md` opens with, one level up from pictures.

**9. One lifecycle, four screens.** Studio (plan and drafts) → Approvals (queue)
→ Content plan (calendar and list) → Deliveries (what shipped). Holo has one
board with three columns. Our sidebar has twelve destinations for what is,
operationally, one job.

---

## 3. The shape we are moving to

Four surfaces instead of twelve destinations. The engine underneath does not
change; the way it is entered does.

```
Home            assistant bar + intent chips
                → setup checklist per surface (locked steps)
                → today's actions
                (absorbs: Dashboard, Today)

Social          Overview  — goal header + Actions board (To Do / In Progress / Done)
                Create    — Idea shelf → Signals → Make something → (later) Clone
                Analytics — the feedback loop, scoped to social
                (absorbs: Studio, Approvals, Content plan, Deliveries, Conversations)

Strategy        one KPI, one target, a cadence, four weeks — a form and an
                artifact, not a transcript
                (absorbs: the Studio's strategy dialog)

Brand           Brand Brief, visual style, channels, connections
                (absorbs: Brand brief, Channels)
```

Insights (Feedback, Prompt analysis, Site audit) stay where they are. They are
not part of this job and folding them in would be tidying for its own sake.

**Studio as a word disappears.** Not because the name is bad, but because the
thing it names — a room where content gets made — is now the Social surface's
Create tab, and keeping a separate Studio entry would reintroduce the split this
whole document is about. The user's instinct was right: promote it to the
dashboard. What that means concretely is that its *conversation* moves to Home
and its *artifacts* move onto boards.

---

## 4. The four surfaces

### 4.1 Home

Three bands, top to bottom.

**The assistant bar.** One input, full width, with intent chips beneath it. Ours
are not Holo's six — we take the ones our engine can actually honour:

| Chip | What it does | Backed by |
|---|---|---|
| Create | one post, now, on a subject you type | new: ad-hoc idea → `generateIdea` |
| Plan | build or reshape the month | existing `propose` / `refine` |
| Check | is this claim supported by our facts? | existing `factCheckFindings` |
| Explain | why did this draft get chosen? | existing `selection` payload |

A chip that has no engine behind it does not ship. The sidebar's own docblock
already states this rule — *every link here goes to a page that does something
today* — and it applies to chips.

**The checklist.** One card per surface, ordered, with locks. This is the single
highest-value borrow in the document, and it costs almost nothing: the states are
all already computable.

```
Social setup                                              2/5  ▰▰▱▱▱
  ✓ Brand brief written              BrandBrief::activeFor() !== null
  ✓ Site analysed                    $project->site_analysis !== []
  ● Connect an account               Channel connected & pinged     [Connect]
  🔒 Set your goal                    ContentGoal exists            (§4.3)
  🔒 Approve your first post          any ContentItem in Approved
```

**Today's actions.** The three or four cards that need a person: drafts waiting
for approval, conversations waiting for a reply, a failed delivery. This is the
existing `Today` screen, reduced to cards and moved up.

### 4.2 Social — the three tabs

**Overview.** A goal header of three numbers — *Week 2 of 4* · *61% of the
month's posts made* · *KPI progress* — over an Actions board.

The board's three columns map onto state we already store, with no new
lifecycle:

| Column | Derivation |
|---|---|
| To Do | `ContentIdea` with no `ContentItem` rows, or items in `Idea` |
| In Progress | any item in `Queued`, `Generating`, `Draft` |
| Done | every item `Approved` or `Published` |

Each To Do card shows what Holo's shows and what we already have on
`ContentIdea`: title, `kind_label` as the angle badge, `thesis` as the rationale,
`production[channel].format` as the content-type chip, `scheduled_for` as the
date. The card's only button is **Create** → `POST studio/ideas/{idea}/generate`.

This is the change that makes the Studio usable. One click, one idea, ~125
seconds (measured — see `social-media-studio-debt.md` §2), and the card moves to
In Progress on its own.

**Create.** Four shelves, in this order.

1. **Ideas** — the current month's `ContentIdea` rows as Holo-style cards. Not a
   calendar. A calendar is for checking cadence; a grid is for choosing what to
   make, and choosing is what this tab is for. The calendar stays, one tab over.
2. **Signals** *(new — the Adomate borrow)* — what the outside world is saying.
   Phase 3; scoped in §5.
3. **Make something** — the named methods. This is where
   `social-media-studio.md`'s four treatments finally get a surface:
   *Real photograph* · *Brand overlay* · *Structured graphic* · *Generated
   scene*. Plus the two that are pure UI over what exists: *Redraw* and *Three
   to choose from*.
4. **Clone** — deferred. It requires an ad library we do not have and a licensing
   position we have not taken.

**Analytics.** `Feedback`, filtered to social channels, plus the KPI curve from
§4.3. No new measurement.

### 4.3 The post composer — four steps forward

Replaces the aside → dialog → inspector → card nesting. One route,
`social/posts/{item}`, four steps, a back button at every one.

```
1  Creative   the picture at full size, the variant strip, the four
              treatments, "what is wrong with this picture"
2  Caption    the body, editable, at full height — not in a 208px scroll box.
               Guard findings and the fact-check verdict inline, where the
               sentence they refer to is.
3  Schedule   channel, format, date, time
4  Publish    the platform preview, then Approve → existing content.approve
```

Nothing publishes from step 4 that does not go through `ApprovalController`.
The permanent ban on autopublish in §4.2 of the social spec is untouched: this
is a nicer road to the same gate.

### 4.4 Strategy — one goal, four weeks

A `ContentGoal` per project per month:

```
kpi        followers | reach | engagement   (one, not three)
target     integer
cadence    posts per week — defaults from $project->weekly_target
weeks      four objectives, one per week, written by the assistant
```

The KPI is what the Overview header counts against, and what the proposal prompt
is told to optimise for. Today `proposalInstructions()` asks for "a point of view
for the month" with no stated objective, which is why its months read as
plausible rather than aimed.

One KPI, not a scorecard. Three simultaneous goals is the same as none, and the
choice being forced is what makes the number on the Overview header mean
something.

---

## 5. What we take, refuse, and take differently

**Take as-is**

- The locked activation checklist. Nothing else answers "what now" as cheaply.
- Overview / Create / Analytics as the repeating tab shape.
- The To Do / In Progress / Done board over one lifecycle.
- One KPI, one target, four weeks.
- The linear four-step composer.
- Named creation methods, each one thing an operator can ask for by name.

**Refuse**

- *Fifteen creation methods.* Most of Holo's are one model call with a different
  label. We have four treatments with genuinely different economics — one costs
  nothing, one costs nothing and needs a browser, one costs a generation. Ship
  four honest ones.
- *Clone.* A wall of other brands' live ads, reskinned. Legally and editorially
  we have not taken a position, and `social-media-studio.md`'s whole argument is
  that the differentiator is the client's own real material.
- *Emails as a third surface.* Out of scope; nothing in the engine writes email.
- *Video.* Deferred in phase 8 on purpose. The composer's step 1 must not imply
  it exists.

**Take differently**

- *Chat.* Holo's is a global bar that opens a workspace. Ours stays attached to
  the artifact it changes — a plan-level conversation on Strategy, a
  draft-level note on the composer's step 1. The existing `VisualDirector`
  design is right and the reason is written down: a note revises stored fields
  rather than appending to a prompt. Keep that; do not make it a chat window.
- *Signals.* Holo shows competitor **ads**. Adomate shows competitor ads **and
  customer reviews**. For a service business — the customer we actually have —
  reviews are the higher-value signal by a distance: they are real language from
  real buyers about a real job, which is the one input `site_analysis` can never
  produce. Phase 3, and it starts with reviews rather than ads.
- *The KPI.* Holo counts followers/reach/engagement, which needs an Instagram
  Insights connection. We can count what we already measure —
  `ContentMetric`, `Feedback`, `citations` — and add platform metrics when the
  connection exists. The header shows what is real and says so when a number is
  unavailable, rather than showing 0.

---

## 6. On connections — what their bug teaches

The user cannot connect Facebook or Instagram to Holo, and the docs explain why
without meaning to: connection is one button and one sentence — *"Grant the
requested permissions"* — with no statement of what is actually required. Meta
publishing needs all of:

- an Instagram **Business** or **Creator** account (a personal one cannot be
  published to at all);
- that account linked to a Facebook **Page**;
- the connecting user as an **admin** of that Page;
- the app holding advanced access to `instagram_content_publish`,
  `instagram_basic`, `pages_show_list`, `pages_read_engagement`;
- the app in **Live** mode, or the user added as a tester.

Any one of those missing produces a successful-looking OAuth round trip and an
empty account list. That is the failure the user hit, and it is unfixable by the
user because nothing tells them which of the five is wrong.

**Our position:** a connection is a screen with a diagnosis, not a button with a
spinner. When a grant returns no publishable account, say which precondition
failed, name the account it looked at, and link to the fix. We already have the
shape for this — `ChannelController::ping()` exists specifically to turn
"configured" into "connected" by making a real signed request. Extend that
principle to every connection, and put the result on the Home checklist as a
✓/●/🔒 row rather than a boolean.

This is worth doing regardless of the rest of the document.

---

## 7. Data model changes

Small, and deliberately so. The engine is not what is wrong.

```
+ content_goals            project, month, kpi, target, cadence, weeks[], confirmed_at
~ content_ideas            + board_state (derived, or a column if the query hurts)
                           + source ('assistant' | 'operator' | 'signal')
+ signals                  phase 3 — source, url, captured_at, body, entities[]
```

`ContentItem`'s state machine is untouched. `ContentPlan` gains a `contentGoal`
relation. That is the whole of it.

New routes, all thin:

```
POST   studio/ideas/{idea}/generate      the one-click Create  ← unlocks §4.2
POST   studio/ideas                      ad-hoc idea from the Home chip
GET    social                            Overview
GET    social/create                     Create
GET    social/posts/{item}               the composer
GET/PUT strategy                         the goal
```

---

## 8. Build order

Sized against what is already there, not from zero.

**Phase A — the unlock (small, do it first)** ✅ *shipped 14 August 2026*

`POST studio/ideas/{idea}/generate`, and a Create button on every idea card.
The pipeline action, the per-idea lock and the dispatch guard already existed
and were already tested; this was a controller method, a route and a button.

Two things came out of building it that the plan did not anticipate. The
per-idea route is **not** gated on plan acceptance — pressing Create on one idea
*is* the human decision the acceptance gate exists to require, at a finer grain
than accepting twenty at once. And `operationProps` now carries `idea_id`, so a
card can say it is the one being written; without it the only feedback was a
banner about the plan, and an operator who pressed Create on a Tuesday could not
tell whether anything had happened.

**Phase B — the Overview board** ✅ *shipped 14 August 2026*

`ContentGoal` + the goal header + the three-column board, at `/social`. The
columns are **derived** from `ContentItemState` — no second lifecycle, no
`board_state` column, because a stored column would disagree with the state
machine the first time a worker moved an item without going through it.

Approvals is *not* redirected here; see §10.1 for why that moved to the end of
Phase C.

**Phase C — the composer** ✅ *shipped 14 August 2026*

Four steps forward at `social/posts/{item}`, replacing four levels of nesting.
Full-height text with the channel's own character counter, guard findings and
fact-check verdicts on the step where the sentence they refer to is, and a
Review step whose button posts to `content.approve` — the same and only gate.

Editing is refused on anything but a `Draft`, and **a segment carrying a
`published_id` is never rewritten**: Threads has no idempotency key, so that
journal is the only record a segment already went out.

Both the board and the approvals queue link into it, which is the duplication
this phase actually removed — see §10.1 for why the queue itself stayed whole.

**Phase D — Home** ✅ *shipped 14 August 2026*

A composer that turns a typed sentence into drafts, the activation checklist,
and what is waiting today.

Two departures from the plan. **It does not absorb the dashboard**: that screen
reports on the engine — runs, impressions, citations, Google, stack health — and
most of it is the article half this release must not disturb, so folding it in
meant either dropping those cards or building a second dashboard with a chat box
on top. And **the checklist's padlocks are real**. The reference padlocks every
step after the current one, which is precisely how a silently-failed Meta
connection locks a user out of everything behind it; here a padlock means a
prerequisite that is a fact, so this deployment — no Meta app, presence off —
completes four of six steps instead of staring at five locks.

Only one chip shipped, not four. "Create" has an engine behind it
(`POST studio/ideas` → an idea → drafting on the spot). Check and Explain read
data that already exists and were not built; the spec's own rule is that a chip
without an engine does not ship.

**Phase E — Create** ⚠️ *partly shipped 14 August 2026*

`/social/create` ships with the Ideas shelf — the month's unwritten ideas as a
grid, each with the one-click Create from Phase A.

**Two of the four treatments could not be built in this environment, and neither
is a design problem.** `social-media-studio.md` proposed drawing the brand
overlay with GD and FreeType because both are already in the container. They
are — but the only typeface in the repo is **woff2**, and GD's `imagettftext`
needs TTF/OTF. The structured graphic needs a second Remotion composition, which
means rebuilding the renderer image — and Docker Hub is unreachable from this
machine's daemon, which is why the running renderer was hand-built in the first
place (`social-media-studio-debt.md` §1).

So the shelf ships the treatments that work — a real photograph, a generated
scene, redraw, and three-to-choose-from, all reachable from the composer's first
step — and the other two stay in the debt file with their actual blockers named
rather than shipping as padlocks nobody can ever clear.

**Phase F — Signals** ✅ *shipped 14 August 2026*

Smaller than planned, because most of it already existed. `signals` has been a
real table since phase 12 — seven sources, eight kinds, weights, fingerprint
deduplication, expiry, and a `content_items.signal_id` column waiting for a
writer. What it never had was a **surface**, so nothing a person could see ever
came of any of it.

The shelf reads `Signal::live()` — unconsumed and unexpired, so it cannot offer
somebody a trend from March — and writing from one carries the signal's id
through the queue onto every post it produces. That last part is the phase:
§3's argument for signals being a table is that the loop learns by source, and
that only holds if `whereNotNull('signal_id')` can answer what listening
actually produced.

Competitor ads and customer reviews are **not** built: both need an ingestion
source this engine does not have. The shelf is empty on this deployment and says
why rather than inventing something to react to.

---

## 9. What this does not change

- Nothing publishes without a person. `ApprovalController` stays the only gate.
- A picture is never deleted; choosing is a promotion.
- The channel decides the crop; the kind decides what the frame shows.
- Per-channel drafting stays three separate model calls. The reason is written
  at length in `ContentStudioAssistant::draftIdea()` and none of this touches it.
- The Brand Brief stays a versioned artifact.

---

## 10. Open questions

1. ~~**Does the Overview board replace Approvals, or sit beside it?**~~ —
   **settled 14 August 2026, and not the way it was asked.**

   Answered "replace". Then the code disagreed with the question: `ApprovalController`
   is a **single queue for articles and social posts together**, and its docblock
   spends a paragraph on why — *"§7 gives the operator one screen and five
   minutes, and a second queue would be a second habit."* A blanket redirect to a
   social-only board would take the only screen where an **article** can be
   approved, which is out of bounds.

   The first answer to that was "social leaves the queue and is approved on the
   board instead". **Building Phase C changed it again, and this is where it
   landed:**

   > **The queue stays unified. What stops being duplicated is the review
   > screen, not the queue.**

   Three things forced it. §7's one-screen rule is argued in the controller
   docblock, in `SocialApprovalTest`'s own comments, and in `spec.md` — and
   executing "replace" literally would have meant deleting the tests that
   encode it. With articles staying in `/approvals` regardless, pulling social
   out would *create* the two-habit split §7 exists to prevent — the opposite of
   what the rethink is for. And the duplication actually worth removing was
   never the queue: it was **two different review UIs over one draft**.

   So a social row in the queue now opens **the composer**, and an article row
   opens the unit card. One queue, one review screen per artefact, no screen
   deleted. The board is the planning and progress view; the queue is triage.

   If the wholesale replacement is still wanted, it is one scope on one query in
   `ApprovalController::index()` plus two tests — deliberately left as a one-line
   change rather than taken unilaterally.
2. ~~**Is the KPI real without an Instagram connection?**~~ — settled: all three
   of the reference's KPIs are offered, each declaring the connection it needs,
   and each locked today because no publisher in this engine reads insights back
   ({@see App\Social\InsightSources}). The header's other two figures — the week,
   and posts signed off against the cadence — are sourced from our own rows and
   always true, so the goal is useful before any connection exists.
3. **Ad-hoc ideas and the content mix.** `ContentMix` enforces balance across a
   proposed month. An operator making six `offer` posts by hand walks straight
   past it. Does an operator-authored idea count toward the mix, and does the
   Overview say so?
