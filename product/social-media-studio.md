# Social media studio

Where the picture on a social draft gets decided by a person.

## Why this exists

The engine generates every picture it publishes, and that is the wrong default
for a real business. The position is not ours; it is the one the practitioners
take. From `social-creative-designer`:

> For product brands, Composite mode is the default for product posts. The
> product — its packaging, labels, and design — must always be the client's real
> asset, never AI-approximated. Generate mode is only appropriate for lifestyle
> or atmospheric content where no specific product appears.
>
> A post with an AI-approximated product is not client-ready.

Everything we ship is Generate mode. The complaint it produces is predictable
and was made: the pictures look like stock, because a fully generated image of a
generic kitchen *is* stock — freshly minted rather than licensed, and no better
for it. A cleaning company's real vans, its real crew, its real before and
after, its actual Lisbon apartments are the things that cannot be produced by
anybody else, and they are the things we never use.

The second reason is text. We forbid words inside generated images because every
model garbles them, which is correct as far as it goes and leaves the teaching
posts — the ones that most need a legible list — with nothing to show. The
answer is not a better model. It is to stop asking a model to draw text and draw
it ourselves.

## The four treatments

Ordered by what they cost to build, which happens to be the reverse of how
often they should be used.

### 1. Real photograph — *building now*

An operator uploads a picture they took. It is cropped to the channel's ratio
and becomes a candidate beside the generated ones. No model, no provider, no
cost, and it is the only treatment that produces something a competitor cannot
also produce.

Assets record whether they were generated or uploaded, because every later
treatment needs to know which asset is the real one.

### 2. Brand treatment

The uploaded photograph, unaltered, with a text overlay drawn by us: brand
colour, brand case convention, a fixed position. GD with FreeType is in the
container, so this needs no new dependency.

What it needs that does not exist yet: the Brand Brief carries `visual_language`
as free prose, and an overlay needs structured values — a colour, a typeface, a
position, a case rule. That is a small migration and a form, and it is the
prerequisite this treatment is waiting on.

The skill's own version of brand mode asks the *image model* to add the overlay.
We deliberately diverge: it garbles text, and we already have the glyphs.

### 3. Composite

The real photograph as the anchor, a generated scene around it. The provider
already takes reference images and `SocialImage::references()` already passes
one, so the plumbing is half built. What it needs is the reference *protocol*:
up to three inputs with explicitly declared roles —

- **A** the real subject: *do not alter it, its markings or its colours*;
- **B** mood, light and palette;
- **C** background and props.

We pass one anonymous reference today, which is why it reads as "vaguely like
last week's picture" rather than as direction.

### 4. Structured graphic

For a `how_to`, a comparison, a set of steps or anything with numbers, the right
artefact is not a photograph. It is a laid-out graphic with real type. From
`graphic-designer`: a self-contained HTML file at 1200×1400 with inline CSS,
screenshotted. Perfect text, brand colours, editable afterwards, no generation
cost at all.

This is what the teaching carousels should be. Today they ship as text slides
with a single generated photograph attached to the post.

Needs a headless browser in the container to rasterise. Largest piece of work
here and the one that removes the most generation spend.

## The surface

The controls that exist now live on the draft card: ask for another, say what is
wrong, pick from a strip of thumbnails. That is the right minimum and it is not
a studio. A studio is a screen where:

- a real photograph can be dropped in;
- the treatment is an explicit choice rather than an implied default;
- candidates are seen at a size a person can judge;
- a carousel has media **per slide** rather than one picture for the post;
- the conversation about the picture is kept with the picture.

## What stays true whichever treatment is used

- The channel decides the crop. 1080×1350 on Instagram, square on Threads,
  16:9 on X — never one size for all three, which is what an Open Graph card is.
- The kind decides what the frame has to show (`PostKind::shot()`).
- A picture is never deleted. Choosing is a promotion and the replaced asset is
  retired, so a rejected picture can be brought back.
- Nothing is published because it was generated. A draft is a proposal.
