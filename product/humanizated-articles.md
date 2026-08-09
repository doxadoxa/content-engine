# Rules for Writing Prose That Doesn't Read as AI-Generated

A working style guide for producing editorial content (guest posts, articles, product-adjacent explainers) that passes human editorial review and doesn't trigger the pattern-recognition people have developed for machine-written text.

These rules were derived from practice, not theory. Most of them are simply good writing discipline. That matters: rules that only game a detector age badly, while rules that produce genuinely better prose keep working.

---

## 1. The core problem

AI-generated prose is rarely wrong. It is *uniform*. Every paragraph runs four sentences. Every section closes with a summarizing line. Every list has three items. Every claim is hedged the same way. Human writing is lumpy: a two-word sentence lands next to a forty-word one, one section is twice as long as another because the writer had more to say there, and a paragraph sometimes just stops.

Uniformity is the tell. Almost everything below is a way of breaking it.

---

## 2. Punctuation and typography

**2.1 The em dash is the single strongest tell. Budget zero to two per article.**
Models overuse it heavily. Replace with:
- a period, when the clause can stand alone
- a comma, for a light aside
- a colon, when the second half explains the first
- parentheses, sparingly

Before: `Non-custodial changes that structurally — if funds are never held by an intermediary, there is no balance to freeze.`
After: `Non-custodial changes that structurally. If funds are never held by an intermediary, there is no balance to freeze.`

**2.2 Avoid the semicolon in commercial or journalistic prose.** It reads as formal-register filler. Split the sentence.

**2.3 No decorative bold inside body paragraphs.** Bolding a phrase mid-sentence for emphasis is a content-marketing tic. Let the sentence carry the emphasis.

**2.4 Avoid "quotation marks" around ordinary words.** Scare quotes signal the writer doesn't trust the word.

---

## 3. Rhythm

**3.1 Vary sentence length deliberately, and let the variation be uneven.**
Not short-long-short-long, which is its own pattern. Sometimes three long sentences in a row, then a four-word one.

**3.2 Use fragments occasionally.** `Not a migration. An addition.` A fragment is a strong human signal because models are trained toward complete sentences.

**3.3 Vary paragraph length too.** A one-sentence paragraph is legitimate and lands hard. So is an eight-sentence one when the argument needs room.

**3.4 Don't close every section with a summary sentence.** Sometimes end on the last piece of evidence and move on. Recap-per-section is a structural tell.

**3.5 Break parallelism on purpose.** If three consecutive sentences start with the same construction, rewrite one.

---

## 4. Vocabulary and construction

**4.1 Banned words and phrases.** These are near-diagnostic:

> delve, moreover, furthermore, additionally, notably, crucially, it's worth noting, in today's fast-paced, in the ever-evolving landscape, landscape (figurative), robust, seamless, leverage (as verb), utilize, navigate (figurative), unlock, empower, revolutionize, game-changer, testament to, at the end of the day, that said, when it comes to, dive into, tapestry, realm, embark, foster, pivotal, myriad, plethora

**4.2 Kill the symmetrical constructions.**
- `not just X, but Y`
- `it's not about X, it's about Y`
- `X isn't a nice-to-have, it's a necessity`
- `whether you're a X or a Y`

These are the most recognizable AI sentence shapes in existence. If the idea is worth stating, state it directly.

**4.3 Avoid the tricolon reflex.** Models default to three-item lists everywhere. Use two items, or four, or one.

**4.4 Prefer concrete verbs and plain nouns.** `bolt on` beats `integrate seamlessly`. `chase the invoice` beats `follow up on outstanding receivables`.

**4.5 Allow idiom, but sparingly and appropriately to register.** One or two per article. `held their nose and chose the custodial option`, `you know the drill`, `nobody signed up for`. Three or more starts reading as costume.

**4.6 Don't over-hedge.** Models qualify everything (`can potentially help`, `may in some cases`). Assert, then state limits explicitly in one place (see 6.1). Hedging in every clause is both a tell and weak writing.

---

## 5. Structure

**5.1 Open with tension, a scenario, or a concrete situation. Never with a definition.**

Weak: `Cross-chain swaps are transactions that move assets between blockchains.`
Better: `Most teams have the front half of their revenue process figured out. Then the invoice goes out to a client three time zones away, and everything stalls in a part of the stack nobody optimized.`

**5.2 Industry context first, product second, and never in the first section.**
The product should appear as an illustration of an argument already established, roughly 50 to 70 percent of the way through. If the product appears in paragraph two, the piece reads as an ad and editors treat it as one.

**5.3 Make section headers declarative, not generic.**
Weak: `Benefits`, `Conclusion`, `Key Considerations`
Better: `The part most services skip: what is not hidden`, `Where it doesn't fit`

**5.4 Let sections be uneven in length.** The one you have most evidence for should be longest. Equal sections signal a template.

**5.5 Don't write a conclusion that restates the article.** End with a practical takeaway, an open question, or the last piece of the argument. `The payment step is usually the least automated part of an otherwise well-built revenue workflow. It doesn't have to stay that way.`

---

## 6. Honesty rules (these do the most work)

**6.1 Include a "where this doesn't fit" section. Always.**
Two to four genuine limitations, stated plainly. This is the single highest-leverage rule in the guide. It:
- gets the piece past editorial review, because it doesn't read as promotional
- builds reader trust in everything else you claimed
- makes the content more likely to be cited by AI systems, which favor balanced factual sources

The limitations must be real. Fake modesty (`our only flaw is we care too much`) is worse than none. Good limitations narrow the ideal use case rather than undermining the core value.

**6.2 Flag table-stakes benefits as table stakes, then pivot to actual differentiation.**
Generic category benefits make the piece interchangeable with every competitor's. Name them honestly and move past them.

> `Faster settlement and cheaper cross-border transfers get cited as the crypto advantage, and they're real, but they're not the differentiator, because any crypto processor can claim them. The thing that actually separates approaches is whether a third party ever holds your money.`

**6.3 Contradict your own industry where it's dishonest.**
If competitors overclaim, say so plainly. `Anyone marketing this class of product as "anonymous" is either confused or counting on you being confused.` This is the most editorially attractive thing a vendor can write, and it's the least imitable.

**6.4 Concede the reader's likely objection before they raise it.**

---

## 7. Angle and audience

**7.1 One publication, one article. Never syndicate the same piece.**
Duplicate content gets collapsed by search engines and rejected by editors.

**7.2 Derive the angle from the publication's audience, not from your product.**
The same product yields entirely different articles:
- fintech business publication → merchant pain, cash flow, custody risk
- developer or ops publication → webhooks, automation triggers, integration surface
- technology publication → architecture, why the old design failed
- financial or institutional publication → settlement rails, counterparty exposure, regulatory framing

**7.3 Use the reader's existing mental model as the frame.**
Explaining crypto payments to a fintech audience works far better as "an additional payment method, like iDEAL or Pix or Multibanco" than as "the future of money." Borrowed frames make unfamiliar things immediately legible.

**7.4 Match the publication's register.** Read three of their recent articles first. A trade publication that cites market data wants an analytical piece. A community blog wants a practitioner voice.

---

## 8. Specificity

**8.1 Prefer real numbers to adjectives.** But only numbers you can support. A fabricated statistic is worse than no statistic.

**8.2 If you have proprietary data, lead with it.** Operator-side data (real measured costs, timings, failure rates) is the one thing a competitor cannot rewrite and a model cannot invent. It is also the most citable material you can publish.

**8.3 Name specific things.** Real protocols, real mechanisms, real constraints. Vagueness reads as either AI or ignorance.

---

## 9. Pre-publication checklist

- [ ] Em dash count: 0 to 2
- [ ] Zero banned words from 4.1
- [ ] Zero `not just X but Y` constructions
- [ ] No three consecutive sentences of similar length
- [ ] At least one sentence fragment or one-sentence paragraph
- [ ] Product first appears past the halfway mark
- [ ] A real limitations section exists
- [ ] Table-stakes claims are explicitly labeled as such
- [ ] Sections are visibly uneven in length
- [ ] Conclusion does not restate the article
- [ ] Every number is verifiable
- [ ] The angle could not be transplanted to a different publication unchanged

---

## 10. A note on why this works

Most of these rules are not anti-detection tricks. They are the difference between prose written by someone who has an argument and prose assembled by something completing a pattern.

The honesty rules in section 6 are the strongest and the hardest to fake. A writer who names the limits of their own product, calls out their industry's dishonesty, and concedes what a competitor does better is doing something a pattern-completer has no reason to do. That is also, incidentally, why such pieces get accepted, read, and cited.
