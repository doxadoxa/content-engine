# Avyo — homepage film script

**Format:** 1920×1080, 30 fps, 55.2 s, no voiceover. Kinetic type over animated
product UI. Built to be watched with the sound on after a click, and to still make
sense muted.
**Music:** original track, synthesised in `music/compose.py`. 100 BPM in D major.
One bar is 2.4 s, or 72 frames, and every scene starts on a downbeat.
**Palette & type:** the marketing page's own colours. Cream `#f8f2e8`, forest
`#17352f`, terracotta `#bc452f`, gold `#f3cf6a`, sage `#55785c`. Instrument Sans
for type, with serif italics for emphasis, as in the page's hero.
**Honesty rule:** no promised rankings, no invented customers. Like the page, the
dashboard numbers are labelled illustrative. The example business is the page's
own: *Home & Co.*, a home-cleaning company.

---

| # | Time | Bars | On screen | Music |
|---|------|------|-----------|-------|
| 1 | 0:00–0:07 | 1–3 | **The question.** A search box on cream types *"what's included in a deep clean?"* Six more questions from other local businesses pop up around it, each tagged Google or AI: a plumber, a dentist, a bakery, a photographer, an electrician. <br>Title: **"Every day, people ask Google and AI about what you do."** | Warm pad and kalimba arpeggio. Soft key clicks under the typing, a pop for each question. |
| 2 | 0:07–0:12 | 4–5 | **The gap.** The questions drift away. Big type: **"When they ask, / will they find *you*?"** An underline draws under *you*. | Arpeggio thins out and a riser builds across bar 5. |
| 3 | 0:12–0:17 | 6–7 | **Meet Avyo.** A forest-green circle opens from the bottom-right corner, echoing the logo's cut corner. The mark assembles: tile, terracotta quarter, cut corner. The wordmark slides in. <br>**"Get found. *Win more customers.*"** | Impact on the downbeat. Bass and a half-time beat come in. |
| 4 | 0:17–0:24 | 8–10 | **01 · Research.** *"Avyo finds the questions your customers ask."* In the product card, three customer questions arrive and get ticked. Each becomes a scheduled article in the content calendar (MON 14 / WED 16 / FRI 18). | Full groove. A tick for each question and a pop as each calendar card lands. |
| 5 | 0:24–0:31 | 11–13 | **02 · Write.** *"Helpful answers, in your voice."* An article builds itself. Illustration, kicker, the headline *"What to expect from a deep clean"* typed out, then body copy and the badge **✓ Based on your business brief**. | Groove continues, with type clicks on the headline. |
| 6 | 0:31–0:38 | 14–16 | **03 · Publish.** *"Published on your website. Automatically, or after your review."* The mode toggle flips to *Review first*, then **Approve** is pressed. The article flies into the Home & Co. journal and a toast appears: **Published · 09:00**. Chips: WordPress, Custom websites. | Lead melody enters. A click on each press and a chime on publish. |
| 7 | 0:38–0:46 | 17–19 | **04 · Measure.** *"See who finds you. Learn what leads to sales."* A forest dashboard, *Your growth, in view*. A search-clicks chart draws upward, followed by AI mentions & citations and purchases (when connected). Labelled *Illustrative data*. | Melody peaks and a clap fill leads into the close. |
| 8 | 0:46–0:55 | 20–23 | **Close.** On sand: **"Help your next customer *find you.*"** Then *"Research, writing and publishing, handled."* The logo lockup and a **Get started →** button hold to the end. | Crash on the downbeat. The drums drop out and the pad and kalimba resolve to D and ring out. |

## Words on screen, in order

1. what's included in a deep clean?
2. Every day, people ask Google and AI about what you do.
3. When they ask, will they find *you*?
4. Avyo — Get found. *Win more customers.*
5. 01 Research — Avyo finds the questions *your customers ask.*
6. 02 Write — Helpful answers, *in your voice.*
7. 03 Publish — Published on your website. *Automatically, or after your review.*
8. 04 Measure — See who finds you. *Learn what leads to sales.*
9. Help your next customer *find you.* — Research, writing and publishing, handled. — Get started

## Sync

Every cue in the film is in `src/timeline.json`, and both the animation
(`src/`) and the music (`music/compose.py`) read from it. To move a beat, edit
that file, then run `npm run music` and render again. Picture and sound can't
drift apart.
