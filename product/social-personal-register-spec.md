# A register where somebody is in the room

**Built 21 August 2026.** `PostKind::Life`, a 15% share of the mix, its own
channels, angles, brief and shot, and an inverted rule in `VisualBriefGuard`.
The open question at the bottom was settled in favour of both halves: the
sixth kind *and* people allowed into the other five, the second of which PR #9
had already done. What is still outstanding is the brand-brief edit, which is
not a code change — see the end of this document.

Written 21 August 2026, out of the session that fixed the Studio's pictures.
That work is in PR #9 and it is worth reading first, because this spec exists
because of what that work could *not* reach: every fix there was to how a
photograph is briefed, and the thing actually missing is a kind of post that
does not exist yet.

---

## The complaint

Seven drafts were reviewed and the reaction to the set was that it is cold —
"no persons, no soul" — and that the dirt is sometimes too much. Both are true,
and both survive the picture fixes.

Here is the whole of `PostKind`:

| Kind | What it is | Who is in it |
|---|---|---|
| `Take` | An argument the brand is willing to make | nobody |
| `HowTo` | A method, shown step by step | a pair of hands |
| `Proof` | Before and after, evidence of a change | nobody |
| `Behind` | The unglamorous part nobody photographs | a pair of hands |
| `Offer` | The outcome somebody is buying | nobody |

Five registers, and not one of them has a person in it as the *point*. `HowTo`
and `Behind` admit hands because hands operate tools. That is the structural
answer to "why is there no soul": there is no register where soul is what the
post is for, so no amount of prompt wording will produce one. A picture brief
can only illustrate the kind of post it was asked to illustrate.

The dirt is the same shape of problem. `Behind`'s shot is "show the dirt
itself"; `HowTo`'s is "the tool in contact with the surface"; `Proof`'s is a
boundary between cleaned and uncleaned. Three of five kinds have grime in their
definition, and the two that do not are the two nobody plans much of.

## What PR #9 already did, so it is not proposed again

- People are no longer forbidden in the frame. `NO_MACHINERY` used to say "fill
  it with one thing at arm's length", a distance nobody fits into, and the
  house rules said "nobody looking into the lens". Both are gone; a person may
  now be the subject and may face the camera as long as they are not posing.
- Dirt is no longer mandatory. `VisualBriefGuard` accepts a home being lived in
  as evidence, alongside residue, contact and the after-state.
- The brand's own `visual_language` now goes last in the prompt and wins any
  disagreement. It had been claiming to override the house rules from the
  middle of the prompt while five absolutes followed it.

Those three make a warm picture *possible*. They do not make one get planned.

## The proposal

**A sixth kind.** Working name `Life`. Its subject is a person and a moment in
their home; the service is why the moment is possible, and is not the subject.
A `Life` post is not a tip, not a proof and not an offer — it is the reason
somebody pays.

Its `brief()` — the register given to the writer — should say that the post is
about a person, that it may be small and particular, and that it may not end in
advice. Its `shot()` should ask for somebody occupied in a room that has been
looked after: the light, the ordinariness, the fact that nothing is being
performed.

**Three things this touches beyond the enum.**

1. `PostKind::fallback()` is `HowTo`, and the docblock on `tryFromLoose()`
   already records what that costs: an unreadable value silently becomes the
   kind the feature exists to stop over-producing. Adding a kind makes that
   worse before it makes it better.
2. `ContentMix` decides how much of a month is each kind. A sixth kind with no
   share is a kind that never gets planned. This is the actual lever on
   "cold" — the mix, not the enum. The `social` skill in
   `coreyhaines31/marketingskills` puts the equivalent pillar at 15% and
   behind-the-scenes at 25%, which is a reasonable place to argue from and not
   a number to copy.
3. `ChannelPlaybook::shape()` supplies the per-candidate shapes a pool is
   written against. A personal post written into a how-to's shapes comes back a
   how-to.

**And one thing that is not code.** The project's `visual_language` currently
reads:

> clean, premium home interiors | professional cleaning teams | before-and-after
> home results | checklist and service-report interface imagery

Two of those four are now actively wrong. "Checklist and service-report
interface imagery" is the exact thing PR #9 refuses, for the reason that an
image model cannot draw legible text and a blank form photographs as one nobody
filled in. And "premium" is on the banned-adjective list in the drafting
contract, because to an image model it means showroom. The brief and the engine
should not be arguing; whoever owns the brand should rewrite those two before
the mix changes, or the new register will inherit the same contradiction the
old one had.

## Why this is not in PR #9

It is a different kind of change. Everything in #9 is prompt wording and one
deterministic guard, verifiable by regenerating seven posts and reading them.
This one changes what a month is made of: a new enum case, a mix that has to be
rebalanced against it, planner shapes, and a brand brief edit that is somebody
else's decision to make. It also cannot be judged on seven posts — a register
is either carrying a month or it is not, and that is a month of output to look
at.

## Open question — settled

*Kept as written, because the reasoning is the reason the answer is "both".*


Is the personal register a *post kind*, or is it a property of the brand's voice
that should show up across all five? A cleaning company's `Proof` post could
have somebody in it; so could its `Offer`. The argument for a sixth kind is that
a mix is the only mechanism this engine has for guaranteeing a share of
anything. The argument against is that it quarantines warmth into 15% of the
calendar and leaves the other 85% exactly as cold as it is now.

My inclination is the sixth kind *and* letting people into the other five —
#9 has already done the second half.

**Decided: both.** The sixth kind exists because a mix is the only mechanism
this engine has for guaranteeing a share of anything, and 15% of the calendar
is the difference between a register that ships and one that is theoretically
available. The other five keep the people PR #9 let in. The quarantine worry
in the paragraph above is real and is answered by the second half, not by
refusing the first.

One thing the build surfaced that this spec did not predict: the guard had to
be *inverted* for this kind rather than relaxed. `Life`'s shot forbids the
cloth, the gloves and the product on purpose — it is the after, hours later,
when the work is invisible — so every honest brief fails a rule looking for
contact or residue. For `life` the question is not "is work happening" but "is
anybody here", and a pair of hands does not answer it. Hands are what the other
five show, and a month of them with nobody attached is the complaint the whole
register answers.
