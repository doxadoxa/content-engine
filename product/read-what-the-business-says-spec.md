# Read what the business says about itself

Written 21 August 2026, after a session spent improving how posts are written
and discovering that the writing was never the problem.

---

## The finding

A month of social drafts was reviewed and the verdict was that the content is
hollow. It is, and not because of the prompts. Here is everything the engine
knows about Cleaning Point, in full:

- `projects.site_analysis` — **1.2 KB**: a sentence of self-description, three
  audience labels, a tone, a visual language, some seed keywords
- `projects.original_data` — **empty**
- `site_pages` — 300 rows of **url, title, meta description**. No body.
- `site_audit_pages` — 260 rows of headings, canonical tags and json-ld. No body.
- the active brand brief — positioning, audience, tone: adjectives about itself

From that the engine is asked for roughly thirty posts a month, under an
instruction it obeys correctly:

> Never invent a fact, a figure, a price or a date. Where there is no evidence
> for something, write the opinion, the question or the framing instead.

Given no facts, opinion and framing is the only legal output. Tautology is not
a defect in the prompt — it is the only thing the design permits. Measured:
**60 of 342 planned ideas have a single digit anywhere in their evidence**, and
the evidence that exists reads like this:

- "Cleaning Point has an article titled 'Professional Blind Cleaning in Lisbon.'"
- "The site corpus includes cleaning guidance."
- "Cleaning Point offers regular cleaning and deep cleans."

That is a sitemap and a tagline. Every register, picture rule and content mix
built on top of it is a better jacket on the same emptiness.

## What is already there, and what it keeps

The fetching exists. {@see SiteLibrary} reads the sitemap, decides which URLs
look like articles from path markers in config, and fetches up to sixty of them
per refresh with a seven-day staleness window. It parses the HTML — and keeps
`<title>` and `<meta description>`. The body is discarded.

The SEO side has no more than this. `TopicLibrary` embeds **titles** to answer
"have we covered this topic", and its docblock is explicit that a services page
is not a topic. The only full text in the database is in `content_items`:
**what this engine wrote itself**, embedded by `CorpusIndex` for internal
linking.

So the state of the system is: full text of everything the engine wrote,
headlines of everything the customer wrote.

## The rule

**Evidence comes from what the business asserts commercially about itself.
Never from editorial content, and never from a page this engine published.**

Three reasons, in order of how expensive they are to learn late:

1. **The commercial pages are where the facts are.** Cleaning Point's sitemap
   has 116 article pages and 184 others. The others include `/services` and
   `/services/add-ons` — what is offered, for how much, in what time, with which
   add-ons. Those are the only pages on the site containing anything checkable,
   and they are the ones the engine has never requested. It reads the blog prose
   and ignores the price list.

2. **Editorial content is derivative.** An article is already an interpretation.
   Sourcing an interpretation produces the posts we have: "Cleaning Point's
   published guidance advises against harsh products" — citing the company's own
   opinion piece as the news.

3. **The loop must not be allowed to close.** The journal will increasingly be
   written by this engine. An engine that cites its own published articles as
   evidence turns its inventions into facts on the second pass. `config` already
   has the same argument about a different field: an unconfirmed goal is "this
   assistant's own previous guess, and feeding a model its own estimate back as
   context is how a made-up number becomes a fact by the third refinement."
   Nothing today would stop that happening with articles, because today nothing
   reads articles for facts at all — the hole is invisible until the SEO side
   starts filling the journal.

## The change

**Classify at read time.** The article/not decision is currently a guess from
URL path markers, and it is used only to decide what to fetch. It becomes a
three-way classification made by a model from the URL, the title and an opening
excerpt: `commercial` (the business stating its offer — services, pricing,
add-ons, coverage, guarantees, about, FAQ), `editorial` (articles, journal,
guides), `other` (contact, legal, language switchers, listing pages). Batched,
not one call per page.

**Keep the body of commercial pages.** Extracted text, whitespace-squished,
capped. Editorial pages keep what they keep now — title and description are all
they are for.

**Feed excerpts to the planner as evidence.** A new context key carrying the
commercial pages' text, replacing nothing: `existing_site_articles` stays, and
stops being a source of truth. Titles answer "what have we covered"; bodies
answer "what is true".

**Refuse self-citation by construction.** A `site_pages` row whose URL matches a
published `content_items.public_url` is excluded from the evidence corpus
whatever it is classified as.

## What deliberately does not change

- **The drafting prompt.** `channelPrompt()` already passes "the only evidence
  you may state" from the idea. Better evidence on the idea is better evidence
  in the post, with no change to the writer.
- **`TopicLibrary`.** Titles are the right input for "has this been covered".
- **Registers, pictures, the mix.** All of that work stands; it was just built
  on nothing to say.

## Risks, and what to do about them

**Context budget.** Cleaning Point's commercial pages exist in four languages —
`/en/services`, `/pt/services`, `/ru/services`, `/uk/services` are the same
facts four times. Read the project's primary locale only, and cap both the
per-page excerpt and the number of pages.

**Classification cost.** Sixty pages per refresh, batched at ~25 per call, is
two or three utility-model calls per weekly refresh per project. Acceptable. A
misclassification is recoverable — the field is stored, so an operator can
correct it and nothing has to be re-fetched.

**A site with no commercial pages.** Some businesses are one landing page. The
rule then yields little, and the engine is back where it started for that
tenant — which is the honest outcome, and better than inventing. It should be
visible rather than silent: if the corpus is empty, the operator should be told
that the plan is being written without facts.

## Success is measurable

The number this fixes is the one that exposed it: **ideas whose evidence
contains a digit**, currently 60 of 342. And the qualitative check is whether a
post can name a price, a duration, a material or a room without inventing one.
