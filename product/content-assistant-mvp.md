# Content assistant — MVP

The assistant is the intent interface for the existing content engine. It does
not replace the calendar, approval queue, or deterministic pipelines. Its first
job is to read what the project already knows about the site and make a useful
proposal before asking the operator to fill in a blank chat.

## First-run contract

1. The operator opens **Studio** for a month.
2. If that month has no assistant proposal, the client asks for one
   automatically. The server uses the saved site analysis, active Brand Brief,
   existing site corpus, original business data, and already-planned work.
3. The response separates site facts, assumptions, objectives, pillars,
   channel roles, open questions, and a dated set of content ideas.
4. The operator can refine the proposal in plain language. Every refinement is
   a new immutable idea version; old ideas remain addressable by drafts already
   generated from them.
5. Accepting a proposal accepts only that assistant version. It does not approve
   the shared `ContentPlan`, because that plan may also contain SEO articles.
6. The operator can generate the next seven-day batch into native Threads, X,
   and Instagram drafts. Nothing is published and no publishing channel needs
   to be connected.

## Data shape

```text
ContentPlan (one project-month)
├── assistant strategy + current/accepted version
├── ContentPlanMessage (conversation and audit trail)
└── ContentIdea (immutable per proposal version)
    └── ContentItem (one native channel draft)
```

`ContentIdea` is the missing semantic layer between a monthly strategy and a
post. An article and three social drafts may eventually be expressions of the
same idea; neither the article nor one channel is forced to be the parent.

## Proposal schema

- summary
- site facts, with a source label
- assumptions
- objectives
- pillars (`name`, `purpose`)
- channel roles for Threads, X, and Instagram
- open questions
- dated ideas (`title`, `pillar`, `thesis`, `evidence`, `goal`, `audience`,
  `angle`, channels)

The site is evidence, not truth. Model output is always presented as a proposal,
and the prompt forbids inventing business facts.

## Deliberate MVP limits

- One assistant workspace per existing monthly `ContentPlan`.
- The initial proposal plans the whole month; generation happens in seven-day
  batches so later copy can still react to new information.
- Drafts are stored and reviewed inside the engine, but exporting and direct
  social publishing remain separate follow-up adapters.
- The assistant chooses and configures the supplied channel recipes. There is no
  node-editor UI for arbitrary pipeline graphs.
- Interactive calls go through the shared `ModelGateway` inside a synchronous
  metering adapter. Each proposal, refinement, or weekly generation is recorded
  as a `content_studio` run with one aggregating step, so retries and multi-idea
  batches retain their full token, cost, and latency totals in the existing
  usage dashboard without storing prompts or model output there.
