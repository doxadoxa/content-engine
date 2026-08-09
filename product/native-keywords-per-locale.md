# Native keywords per locale

A locale row currently targets a *translated* subject, not a researched one. The
Russian article about post-renovation cleaning is written in Russian, about the
right thing, and aimed at a phrase nobody has checked anyone searches for. This
is the work that closes that gap.

Deferred deliberately on 2026-08-09, after the interim fix below shipped. It is
not urgent — the articles are correct and readable — but it is the difference
between publishing in three languages and ranking in three markets.

## What exists now

`schedule_calendar` creates one row per written locale by copying the researched
unit. `localise_variants` then rewrites each copy's `title` and `target_query`
into its own language with one cheap model call per unit.

That fixed the bug it was written for: the copies used to keep the source
language's title and query, so `write_outline` for a Russian article was
prompted with `Target query: limpeza pós-obra` / `Language: ru` and answered
with Portuguese entities, which `cover_entities` then required the draft to
name. Published Russian articles read "Espuma de poliuretano, или монтажная
пена".

Two things are true of the result and both are honest:

- `target_query` on a locale row is *the subject said in that language*. It has
  no search volume behind it and must not be read as a researched keyword.
- `topic_volume` and `topic_difficulty` are **null** on locale rows. They used
  to be copied from the source unit, which meant a Russian row carried a
  Portuguese market's figures. Absent beats invented: `ContentItem::addLocale()`
  had always documented this and `ScheduleCalendar` had always undone it.

`monthly_volumes` *is* still carried across, and should stay that way. When in
the year a subject peaks is mostly a fact about the subject — window cleaning
peaks in spring whichever language the question is asked in — where the figure
is a fact about a market.

## What this work adds

Research the locale's own market, so a locale row is a planned unit like any
other rather than a translated one.

1. **A research pass per written locale.** `KeywordSource` already takes a
   market; nothing calls it with more than one. Seed it with the localised
   subject `localise_variants` produced and ask that market for candidates.
2. **Choose from what comes back**, rather than accepting the translation. The
   phrase Russians type is often not the translation of the phrase the
   Portuguese type — different word, different specificity, sometimes a
   different question entirely.
3. **Fill in the columns honestly.** `topic_volume`, `topic_difficulty` and
   `monthly_volumes` from that market's data, replacing the inherited curve.
4. **Let a locale decline.** A subject with real demand in Portugal may have
   none in Russian. The right outcome is then *no Russian row for this unit*,
   not a Russian row targeting nothing — and the planner should be able to say
   so rather than being obliged to produce one row per locale per unit.

## What it costs

Vendor rows, not model tokens. Roughly one research call per unit per extra
locale: a three-locale project on 30 units a month goes from ~30 keyword lookups
to ~90. That is the decision to make before building this — it is a line item,
and it scales with locales × units, not with articles published.

## What breaks if it is done carelessly

- **The locale group must survive it.** The three rows of a unit are one subject
  in three languages, and that is what makes them shareable: the repurpose tree,
  the seasonal band, and the photographs `HeroImage` lends between locales all
  assume it. If choosing a native keyword is allowed to drift the *subject*, a
  Russian article gets an English article's pictures of something else. Native
  keyword, same subject — or no row at all (point 4). Not a third subject.
- **`weekly_target` is counted in units, not rows.** A locale declining changes
  how many rows a month has and must not change how many units it has.
- **The calendar card is a decision surface.** Whatever ends up in
  `topic_volume` has to be that row's market or stay null; an operator approving
  a month reads those numbers.

## Not in scope

Per-locale *pricing* of the same article, and per-locale scheduling. A unit's
locales publish on one date today and there is no evidence that is wrong.
