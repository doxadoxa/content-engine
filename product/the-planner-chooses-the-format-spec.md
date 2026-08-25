# The planner chooses the format

Written 24 August 2026, after putting four of our ideas through another tool
and discovering that the comparison could not have gone any other way.

---

## The finding

Four ideas were drafted and their images reviewed, then the same four ideas were
handed to a competing tool. It returned five-slide carousels; we had returned
single photographs. The verdict on the slides themselves was that ours are
better — but not one of our eight posts was a carousel, and not by choice.

`content_ideas.content_format` is **null on all 21 ideas** of that month. It is
null on every idea ever planned. The only code that writes the column is
`ContentStudioController`, which is a human clicking a chip in the Studio. An
unattended run therefore always falls through to `ContentIdea::format()`:

```php
return $this->content_format
    ?? ($this->kind->instagramFormat() === 'carousel' ? Carousel : Image);
```

And `PostKind::instagramFormat()` returns `carousel` for **how_to and nothing
else**. The month held four how-tos; the four ideas drafted were take, proof,
behind and life. A carousel was arithmetically impossible.

`ContentFormat`'s own docblock already said so: *"the renderer we stood up to
draw real panels sat mostly idle."*

## Why this is the format's problem and not the picture's

The preceding review blamed the photographs. Two of the four criticisms were
misdirected:

- **`behind`** argued for a *published checklist*. A checklist is correctly
  banned as a photographic prop — diffusion garbles glyphs, which is how a
  brass nameplate came back reading `APARTMEИS`. It is also the natural content
  of a rendered panel, where the type is laid out rather than hallucinated. The
  engine owned the right artefact and could not reach it, because `behind` is
  not `how_to`.
- **`take`** argued for continuity between visits. Its photograph — hands
  righting two cushions — carried none of that and the caption did all the
  work.

The `behind` caption contained the sentence *"Tools may be prepared by task, but
the real standard is knowing what each visit can be reviewed against."* That
sentence exists because the writer could see its own picture missing the
argument and bridged the gap in prose. A caption apologising for its image is a
format error wearing a writing error's clothes.

## What is already there, and what it keeps

Everything downstream of the decision. This is the unusual case where the
feature is built and only its input is missing:

- `ContentFormat` — three cases, `visual()`, `isAvailableOn()`, `on()`
- `CarouselPanels` + `PanelRenderer` + `SlideLayout` — panels drawn by the
  Remotion service, with real type
- `ContentStudioAssistant::carouselContract()` — 5–8 slides, no layout twice in
  a row, cover opens a gap the last slide closes
- The drafting gate at `outputContract()`, which already asks for `slides` when
  the format resolves to carousel
- The spend gate for `Text`, which already skips buying a photograph

The one live path with no producer is `Text`. Its docblock says it: *"nothing
ever produced it, so every post bought a picture whether the post wanted one or
not."* Letting the planner choose it makes that path live for the first time.

## The rule

**Format is a decision about what the idea contains, made where the whole month
is visible.**

Not about the kind. `PostKind::instagramFormat()` argues that "only teaching is
reliably a sequence" and that a look behind the work is one image — and the
checklist post is the counterexample. The determinant is whether *this idea*
has internal structure: steps, a comparison, a list, a sequence of figures. A
`behind` post about eight checkpoints has it; a `behind` post about why the
cloths are colour-coded does not.

This is the third time this argument has been made in this codebase, and the
first two are the precedent for the shape of the fix:

1. `ContentMix` — kinds are a property of the set, because no amount of checking
   one idea at a time catches a month where all twenty are how-tos.
2. `content_ideas.shot` — pictures are a property of the set, because the drafts
   fan out in parallel and each briefs its photograph blind. Measured before it
   moved: 33 of 40 briefs described a hand.

Format is the same. Left per-draft it cannot be varied at all; left to the kind
it is decided by the wrong variable.

## The change

**Ask the planner for `format`, in the same answer as `kind` and `shot`.** One
new key in the proposal contract, alternation derived from
`ContentFormat::cases()` rather than typed — the enumeration of kinds went stale
in its third copy and this is the fourth such list.

**Say what each format is for**, derived from the enum the same way
`PostKind::vocabulary()` is, so the prompt and the type cannot disagree.

**Add `FormatMix`, modelled exactly on `ContentMix`.** Per-idea judgement drifts
to a single answer — that is the whole reason `ContentMix` exists, and the
competing tool demonstrated the drift in the other direction by making every
idea a carousel. Same instrument: soft targets in the instruction, and
`findings()` returning corrections into the loop that already runs.

Two things are refused, both failures of the set:

- **A month made entirely of one format.** Symmetric: it refuses today's
  all-image months and an all-carousel month equally.
- **Carousels above a ceiling**, counted against the ideas that can actually
  carry one. Cost does not argue for the ceiling — a panel is compute rather
  than a generation, so carousels are the *cheapest* thing we make. Reader
  fatigue does.

**Compute the carousel denominator from the kind targets.** `Carousel` is
Instagram-only, and `PostKind::channels()` sends only how_to, proof, behind, life
and offer there — `take` never. Asking for "about 7 carousels" out of 21 ideas
when a quarter of them cannot be one is an instruction that gets resolved
against whichever half the model likes least. `ContentMix::targets()` already
gives the expected count per kind at instruction time; sum the Instagram-bound
ones.

**Correct an impossible choice silently at parse.** A carousel on a `take`
becomes an image, the way `channelsFor()` already normalises channels the model
got wrong. `ContentFormat::on()` would do it at render time anyway; doing it at
parse means the stored plan and the Studio chip tell the operator the truth
rather than a format that will not happen.

**Stop briefing a photograph for a text post.** `outputContract()` asks for the
six art-direction fields unconditionally. For `Text` the answer is written,
parsed, guarded and then dropped on the floor at the spend gate. Drop the
`visual` object from the contract instead, and tell the writer there is no
picture so the post does not reference one.

**Add `content_format` to `preserveDraftedIdeas()`.** It rebuilds frozen ideas
field by field, and a field missing from that list is silently lost on the next
refinement. This is the same stale-second-copy failure named twice above; it
belongs in the change that introduces the field, not in the bug report after.

## What deliberately does not change

- **`PostKind::instagramFormat()`.** It stays as the fallback for every idea
  planned before this existed, and for a planner that omits the key. Its
  reasoning is wrong as a *rule* and fine as a *default*.
- **`carouselContract()`, `SlideLayout`, `CarouselPanels`.** The slides are good;
  the user's own comparison said so. Nothing here touches how one is drawn.
- **The mix of kinds.** Format is chosen after kind and does not feed back into
  it.
- **`VisualBriefGuard`.** `check([])` already returns no complaints, so a text
  post passes it without a special case.

## Risks, and what to do about them

**The Text path has never run.** It is gated correctly at the spend step and
`StudioCandidate::$visual` already defaults to empty, so the failure mode is a
post with no picture rather than a crash — but it is untested in production.
Give it a low share and a test that drafts one end to end.

**A carousel is 5–8 panels of copy.** More words under the brand's name per
post, and `StudioPostGuard` already checks panel text, but a month that shifts
from mostly-image to mostly-carousel multiplies what a bad brief can publish.
The ceiling limits the blast radius.

**Renderer availability.** `config/content_studio.php` calls an absent renderer
a skip rather than a failure, and a carousel there "ships the way it always
did". A deployment without the Remotion service now gets more posts on that
path. Behaviour is unchanged and degraded rather than broken.

## Success is measurable

The number that exposed this is **carousels per month: zero**, across every
month ever planned, with a renderer built to draw them.

The qualitative check is the `behind` idea. Planned again, it should come back
`carousel`, and its checklist should appear as a drawn panel with legible type
instead of as a sentence in a caption apologising for a photograph of cloths.
