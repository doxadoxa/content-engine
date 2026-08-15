# Social media studio — what is owed

Written 10 August 2026, at the end of the session that built the studio. Each
item is something we knowingly left, with the reason, so that picking it up
later does not start with an archaeology dig. Companion to
`social-media-studio.md`, which is the plan; this is the bill.

---

## 1. The renderer image in the local stack is not built from its Dockerfile

**High. Nothing is broken; the artefact is a lie.**

`docker compose ps` shows a healthy `renderer` and it genuinely renders — the
seven panels on the 3 August carousel were drawn by it. But the image tagged
`content-engine-renderer:dev` on this machine was **not** built from
`docker/renderer/Dockerfile`. It was built `FROM content-engine:dev` with
`nodejs` and `chromium` added by apt, and tagged by hand so compose would find
it.

**Why.** Docker Hub is unreachable from the Docker daemon on this machine:

```
# from inside a container
registry-1.docker.io     FAIL
auth.docker.io           FAIL
registry.npmjs.org       OK
```

Egress works — npm and apk are fine, which is why apt could install Node. Only
the registry is blocked. The daemon proxies through
`http.docker.internal:3128` with `NoProxy=hubproxy.docker.internal`, which is
the shape Docker Desktop's Registry Access Management takes. A foreground
`docker pull node:22-bookworm-slim` produced not one byte in nine minutes.
Images already on the machine were pulled before the block existed.

**What it costs.** The running image carries PHP and Caddy that a Node service
has no use for — 1.9 GB against a few hundred megabytes. And the committed
Dockerfile is unproven: everything above `FROM` has been exercised live, the
`FROM` line and the Chromium library list have not.

**To settle it.** Unblock Hub (Docker Desktop → Settings → Resources → Proxies,
and check whether this machine is signed into an organisation with Registry
Access Management), then `docker compose build renderer` — it rebuilds from the
committed file and overwrites the hand-tagged image. If Hub cannot be
unblocked, the honest alternative is to change the committed base to something
reachable and drop the PHP layer deliberately rather than by accident.

## 2. ~~The timeout chain~~ — settled 10 August 2026

Kept rather than deleted, because what the fix turned up is worth reading.

Raising the numbers was the small half. Writing a test that reads the real
config found that the ordering was broken in three places, two of them older
than this session and neither of them the one we were worried about:

- `AskAssistants` asked for 1800s while the expensive worker stopped at 900, so
  the paid visibility sweep its docblock exists to protect was being cut in half
  every time — the exact failure the docblock warns about, at 900 instead of the
  300 it was written against.
- `ApplyContentStudioAction` asked for exactly the worker's 900. A tie, so which
  fired first was a race.
- **Every step that did not override its timeout** asked for the default 300 on
  a cheap worker that stops at 120. One default cannot serve two pools that are
  deliberately an order of magnitude apart, so the default is per queue now.

Now: cheap step 90 < cheap worker 120; expensive steps ≤ 1800 < expensive worker
2100 < `retry_after` 2700. Studio's own step is 1200, sized from six measured
runs (79, 87, 266, 382, 416, 499 seconds on a four-idea week).

`PipelineTimeoutChainTest` reads the config and the step classes rather than
restating the numbers, so the next person to raise one and forget the others
finds out in the suite.

**And then settled properly the same day.** A run per idea, as the note above
said it should be. `generate_week` became a fan-out that reads which ideas are
next and dispatches one run each; `generate_idea` drafts one. The dispatch guard
in `ContentStudioOperations` is scoped to what the operation acts on — the plan
for proposing and refining, which contend on the same rows, the idea for
drafting, which does not — because a plan-wide guard collapses the fan-out on
the spot and a five-idea week produces one idea and reports success.

Measured live afterwards: the fan-out finishes in under a second and the four
idea runs took 82, 110, 128 and 129 seconds. The step's deadline came back down
from 1200 to 300 and no longer has anything to do with how full the calendar is.

A failing idea now fails its own run and the rest of the week stands, which is
a property the suite asserts rather than a hope.

## 2b. The original note, kept for context

**Medium, and it will bite on a busy week rather than today.**

A Generate batch used to be one model call per idea. It is now three channels ×
four candidates, plus up to two fact checks per channel on a YMYL project, plus
the pictures. Five ideas in a week is around sixty model calls and fifteen
images.

Unchanged: `ApplyContentStudioAction::timeout()` is 900, the
`pipeline-expensive` supervisor is 900, and `REDIS_QUEUE_RETRY_AFTER` is 1200.

Half of the risk was removed — drafts commit per idea now, so a killed batch
keeps what it wrote and a retry finishes the rest rather than reporting a
partial week as a whole one. The other half stands: a long batch can still be
killed mid-run.

Not raised unilaterally because the chain is load-bearing. `config/queue.php`
argues at length that inverting these numbers hands a running job to a second
worker, and a duplicated Threads publish is a duplicated post. Raising 900 means
raising all three deliberately: step, supervisor, `retry_after`.

## 3. Brand overlay on photographs — prerequisites built, treatment not

**Low, and *not* cheap now — updated 14 August 2026 while building Phase E of
`studio-rethink-spec.md`.**

`VisualStyle` exists, the brand carries a colour, an ink, a position and a case,
the form edits them, and the renderer draws text with the app's own typeface.
What is missing is the treatment itself: take an uploaded photograph and put the
brand's words on it.

Note the deliberate divergence from `social-creative-designer`, which asks the
*image model* to add the overlay. We will not: it garbles text, and we already
have the glyphs and a browser.

**What "cheap now" got wrong.** `social-media-studio.md` says GD with FreeType
is in the container so this needs no new dependency. Both halves are true and
the conclusion still does not follow:

```
gd: loaded, FreeType: yes, PNG: yes, JPEG: yes     # in the app container
find / -name '*.ttf'                               # → nothing
resources/fonts/instrument-sans/*.woff2            # the only typeface we ship
```

`imagettftext()` needs TTF or OTF. Everything we have is **woff2**, which the
renderer can use because a browser can and GD cannot. So the treatment needs one
of: a TTF of Instrument Sans committed beside the woff2 files (OFL, so
permitted), a woff2→ttf conversion at image build time, or the renderer path in
§3b below.

## 3b. Structured graphic — blocked behind the renderer, not behind design

**Medium, and blocked by §1 rather than by anything about the treatment.**

The fourth treatment — a laid-out graphic with real type, for how-tos,
comparisons and anything with numbers — needs a second Remotion composition
beside `panel`. The renderer today declares exactly one, and adding another
means rebuilding the image.

Which cannot be done on this machine: §1 above is still open, Docker Hub is
still unreachable from the daemon, and the running renderer is still the
hand-built one. So this is not "not designed yet"; it is one `docker compose
build renderer` away from being buildable, behind a registry block.

Phase E shipped the treatments that work and left these two out of the interface
entirely, rather than rendering padlocks an operator could never clear.

## 4. Composite is parked on purpose

**Not debt so much as a decision to revisit if the customer changes.**

The skill's composite mode anchors a real product photo in a generated scene,
and it is right — for product brands. A cleaning company has no product. Its
real assets are the crew, the van, the actual apartments; those are not objects
to drop into a generated scene, they are the scene. Compositing them would
produce an AI room with a real person pasted in, and the seams are exactly what
reads as fake.

Revisit when a project with an actual physical product is onboarded.

## 5. The scorer's weights are per-channel in one place only

**Low.**

`questionBonus` moved to `ChannelPlaybook` — Threads 18, X 8, Instagram 14 —
after a live run showed the Threads bonus dragging both channels of an idea onto
the same question. Every other weight is still shared from `PostFormat`.

Watch for the same symptom elsewhere: if an X post keeps scoring lower than a
Threads post that is objectively worse for X, the next weight to split is
`CAPTION`.

## 6. Not ours, but still true: the signed-out pages sell a different product

Carried from `code-review-2026-08-08.md` (UX-01). The login and password-reset
screens are branded **viddy** and promise video planning and editing; the
product behind them is Content Engine. Worth noting here because the video
question came up again while planning the renderer: the landing already promises
what phase 8 deliberately deferred.
