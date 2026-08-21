<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Media\HeroImage;
use App\Media\SocialImage;
use App\Models\BrandBrief;

/**
 * The art direction a generated picture needs before it stops looking generated.
 *
 * Every social draft the Studio made was illustrated by handing
 * {@see HeroImage::for()} the draft's title, and that method opens its prompt
 * with "An editorial image for an article titled:". A social draft's title is
 * the idea's title with the channel's name stuck on the end, so the sentence
 * the image model actually received was *An editorial image for an article
 * titled: Why we version the brief — Threads*. Given that, a stock illustration
 * is not the model failing; it is the model doing as it was told.
 *
 * The fix is not a better sentence. An image model given one sentence has to
 * invent the other five decisions a photograph is made of, and it invents the
 * average of its training data every time — which is what "generic" means. So a
 * prompt here names all six:
 *
 *   1. **Subject** — what is in the frame, specifically.
 *   2. **Composition** — the framing and the angle.
 *   3. **Action** — what is happening.
 *   4. **Location** — where, with enough of the place to be a place.
 *   5. **Style** — the aesthetic, which is where the brand's visual language goes.
 *   6. **Camera and light** — the shot, directed rather than described.
 *
 * **The six come from the writing model, not from a template here.** The model
 * that just wrote the post is the only party that knows what the post is about;
 * a template in this file could only ever paraphrase the title again. So the
 * draft contract asks for the six fields and this class assembles them, which
 * also means a missing field is visible as a missing field rather than as a
 * vaguer prompt. {@see fromBrief()} is the tolerant path for a model that
 * answered with a sentence instead of an object — it becomes the subject, and
 * the remaining five stay at the house defaults rather than at nothing.
 *
 * **What is not negotiable is at the end and comes from us.** No text in the
 * image, because every one of these models garbles it and a garbled word in a
 * brand's picture is worse than no word. No logos, no watermarks, nobody
 * looking down the lens. Those are house rules, so they are appended after the
 * model's six and cannot be overridden by them.
 */
final readonly class SocialImagePrompt
{
    /**
     * The house defaults for the five elements a bare sentence does not supply.
     *
     * Chosen to be the opposite of the stock-illustration failure mode: a real
     * place, real light, and a shallow depth of field, because the thing that
     * most reliably separates a photograph from an illustration is that
     * something in it is out of focus.
     */
    private const string DEFAULT_COMPOSITION = 'a close three-quarter view, subject filling most of the frame';

    private const string DEFAULT_ACTION = 'the moment the work actually changes something, not a tidy '
        .'gesture near it';

    private const string DEFAULT_LOCATION = 'a real working space with the ordinary clutter left in';

    /**
     * The aesthetic, and the one word that is deliberately absent from it.
     *
     * "Editorial" used to be here and it is what was producing the complaint.
     * To an image model that word means the magazine set: a room styled for the
     * camera, every surface new, the light placed. Photojournalism means the
     * opposite and costs nothing to ask for instead.
     */
    private const string DEFAULT_STYLE = 'documentary photograph, unstyled, shot as it was found';

    private const string DEFAULT_LIGHT = 'available light from one window, shallow depth of field, no flash';

    /**
     * The camera, named rather than implied.
     *
     * The single largest lever on whether the output reads as a photograph.
     * Asked for "photorealistic", these models render their average of stock
     * imagery — evenly lit, everything sharp, nothing in the way. Given a body,
     * a lens, an aperture and a film speed they render an image made by
     * somebody standing somewhere, which is a different thing.
     */
    private const string CAMERA = '35mm lens at f/2, ISO 400, handheld at chest height, slight motion in '
        .'the frame, focus on the subject and the background falling away';

    /**
     * What stops it looking like a catalogue.
     *
     * Every item is a thing a real photograph has and a stock image does not.
     * Perfection is the tell: matched objects, cleared surfaces, unmarked
     * finishes, symmetrical framing. Asking for the opposite is more reliable
     * than asking for "realistic", which the model reads as "high resolution".
     *
     * "Worn edges" used to be in this list and it is the clause that had to go.
     * To an image model that is a licence to damage the set, and it took it:
     * worktops came back chipped, gouged and split down the corner. For most
     * brands that is a wasted picture. For this one it is an argument against
     * the service — the promise is a home that is looked after, and the picture
     * was showing one falling apart. Hence {@see NO_DAMAGE}, which the list
     * above cannot be allowed to outvote.
     *
     * **Nothing here names an object any more, and that is the second lesson.**
     * "A cable that was not tidied" was meant as an example of the register.
     * The model read it as a requirement and delivered: a stray black cable
     * appeared in every picture of one regenerated set, including one hanging
     * off a lacquered white door. An example in a prompt is an instruction, so
     * this asks for the quality and lets the scene supply its own evidence.
     */
    private const string IMPERFECTION = 'Real and lived-in: the ordinary traces of a home somebody uses, '
        .'left where the day left them rather than arranged. Framing slightly off-centre as a person '
        .'would hold it. Not a showroom, not a catalogue, not a magazine set, nothing arranged for the '
        .'camera, and nothing added to the frame to look casual.';

    /**
     * The line between "used" and "broken", which the model does not draw itself.
     *
     * Every signal of life this brand can show is something cleaning removes:
     * dust, marks, residue, crumbs, water spots. Everything cleaning cannot fix
     * — a chip, a crack, a split edge — is a different photograph, about a home
     * nobody is maintaining, published by the company paid to maintain it.
     */
    private const string NO_DAMAGE = 'Used, not broken: nothing chipped, cracked, gouged, split, peeling '
        .'or in disrepair, no damaged furniture or worktops. What shows here is dirt, dust, marks and '
        .'residue — the things cleaning removes — on surfaces that are otherwise sound.';

    /**
     * The failure modes these models actually have, named individually.
     *
     * A generic "no artifacts" does nothing. Each clause below is a specific
     * thing that came back wrong: door and cabinet hardware multiplied and
     * fused into shapes no manufacturer makes, rooms whose walls do not meet,
     * furniture floating a few centimetres off the floor. They cluster on
     * architecture and on hardware, which is also why {@see build()} asks for
     * those to stay out of focus — a defect the lens has already softened is a
     * defect nobody sees.
     */
    private const string ARTIFACTS = 'Nothing malformed: no duplicated or fused handles, hinges, taps or '
        .'switches, no hardware with an impossible design, no doors or walls that do not line up, no '
        .'furniture floating off the floor, no repeated or mirrored objects, no extra or missing '
        .'fingers, no melted edges between surfaces. Where two surfaces meet, the join stays a join: no '
        .'trench, slot, channel or cavity opening into a worktop, no gap cut through a solid surface, no '
        .'seam that turns into a hole. Every object is one whole object of one material and stays '
        .'separate from what it touches — nothing baked into anything else, and the thing in the hand is '
        .'only that thing: a cloth is cloth all the way through, with no food, packaging or second '
        .'object fused inside it.';

    /**
     * The one instruction that outranks the model's own subject.
     *
     * Appliances and cabinetry are where these models fail hardest, and they
     * fail in a way a viewer sees instantly: an open dishwasher whose basket
     * slid out from under an unbroken run of worktop, with a hob standing over
     * the same cavity. Softening the background was not enough, because the
     * brief had asked for the appliance as the *scene* — the defect was in the
     * subject, so the subject is where it has to be refused.
     *
     * **It used to refuse people along with them, which was never the point.**
     * "One thing at arm's length" is a distance, and at that distance nobody
     * fits: the rule aimed at dishwashers took the human out of every frame,
     * and a month of a home-cleaning brand's pictures came back as gloved
     * hands and grime with no one attached. The failure this guards against is
     * *machinery rendered as architecture*, not company in the room.
     */
    private const string NO_MACHINERY = 'Do not build the frame around an appliance, a run of cabinetry '
        .'or an empty room. Build it around one thing near the camera — a surface, a cloth, a tool, a '
        .'person at work, somebody using the room — and let everything structural sit behind that and '
        .'out of focus. A person may be the subject, and may fill the frame.';

    /**
     * What makes the frame worth stopping on.
     *
     * Asked for as a requirement rather than a style, because "documentary" on
     * its own produced a series of competent photographs of nothing happening.
     * Every item is something with visible information in it: texture at a
     * scale people do not usually see, a change mid-happening, evidence.
     */
    private const string INTEREST = 'The frame has to be worth stopping on: real texture at close range, '
        .'a change caught mid-way, marks and residue that are actually there. Not a person being tidy in '
        .'a clean room.';

    private function __construct(
        private string $subject,
        private string $composition,
        private string $action,
        private string $location,
        private string $style,
        private string $light,
    ) {}

    /**
     * The six fields as the drafting model returned them.
     *
     * Anything missing or blank falls back to the house default rather than
     * being dropped, because a prompt with a hole in it is the situation this
     * whole class exists to prevent.
     *
     * @param  array<string, mixed>  $brief
     */
    public static function fromFields(array $brief, string $fallbackSubject): self
    {
        return new self(
            subject: self::field($brief, 'subject') ?? $fallbackSubject,
            composition: self::field($brief, 'composition') ?? self::DEFAULT_COMPOSITION,
            action: self::field($brief, 'action') ?? self::DEFAULT_ACTION,
            location: self::field($brief, 'location') ?? self::DEFAULT_LOCATION,
            style: self::field($brief, 'style') ?? self::DEFAULT_STYLE,
            light: self::field($brief, 'light') ?? self::DEFAULT_LIGHT,
        );
    }

    /**
     * A model that answered with one sentence, or none at all.
     *
     * The sentence is the subject and nothing else, which is honest: it is the
     * only one of the six it can be read as.
     */
    public static function fromBrief(?string $brief, string $fallbackSubject): self
    {
        $subject = trim((string) $brief);

        return self::fromFields([], $subject === '' ? $fallbackSubject : $subject);
    }

    /**
     * The prompt, as the image provider receives it.
     *
     * Labelled lines rather than a paragraph. Every one of these providers
     * weights a prompt by position and by clause, and six labelled clauses is
     * the shape their own documentation asks for — a paragraph reads as one
     * long subject with modifiers, which is how a specific brief turns back
     * into a generic picture on the way in.
     */
    public function build(ChannelPlaybook $playbook, ?BrandBrief $brief = null, ?string $shot = null): string
    {
        $visual = $brief === null ? '' : trim($brief->visual_language);

        return implode("\n", array_filter([
            "Subject: {$this->subject}",
            "Composition: {$this->composition}, framed for a {$this->ratio($playbook)} crop. Keep the "
                .'subject close and let the room fall out of focus behind it — architecture and door or '
                .'cabinet hardware should not be the subject of the frame.',
            "Action: {$this->action}",
            "Location: {$this->location}",
            "Style: {$this->style}",
            $shot === null || trim($shot) === '' ? null : 'What this picture has to show: '.trim($shot),
            'Camera: '.self::CAMERA,
            "Light: {$this->light}",
            self::INTEREST,
            self::IMPERFECTION,
            self::NO_DAMAGE,
            self::NO_MACHINERY,
            self::ARTIFACTS,
            // The objects, not only their content. Banning the words alone left
            // the props in the frame with nothing written on them, which is how
            // a post about naming a standard came back as a photograph of a
            // blank form.
            'Not in this image: no text, no lettering, no numbers, no logos, no watermarks, no user '
                .'interface, no charts — and nothing whose purpose is to carry them: no clipboards, '
                .'checklists, forms, notebooks, paperwork, labels turned to the camera, signage, phones, '
                .'tablets or screens, blank or otherwise. No stock-photo staging: nobody posed for the '
                .'camera, nobody holding a product up for it, no handshakes, no people pointing at '
                .'screens. Somebody caught mid-task who happens to be facing the camera is a '
                .'photograph; somebody presenting themselves to it is an advertisement.',
            // Last, and the only thing here that may overrule what is above it.
            // The order used to say otherwise: this line sat in the middle
            // claiming to override "the style above" while five absolute rules
            // followed it, and the absolutes won. That is how a brief asking
            // for "professional cleaning teams" produced eleven months of
            // disembodied hands — the brand asked for people and the house
            // rules quietly refused, with nothing anywhere recording the
            // disagreement.
            $visual === '' ? null : 'The brand this is for describes its own pictures as follows, and '
                ."where any instruction above disagrees with this, this wins: {$visual}",
        ]));
    }

    /**
     * The same brief, shot a different way.
     *
     * What a second opinion on a photograph actually is. The subject, the
     * action and the location are the *post* — changing them produces a
     * picture of something else, which is not a variant, it is a different
     * idea. What a photographer varies is where they stood and what the light
     * was doing, so that is what varies here.
     *
     * Deterministic by index rather than random: a variant an operator liked
     * has to be reproducible, and `Math.random`-shaped output makes "give me
     * that first one again" impossible to honour.
     */
    public function variation(int $index): self
    {
        $compositions = [
            $this->composition,
            'much closer, filling the frame with the detail itself, most of the scene cropped away',
            'a step back and slightly above, the subject small against what surrounds it',
        ];

        $lights = [
            $this->light,
            'hard low sun across the surface, long shadows raking the texture',
            'flat overcast light from a large window, no visible shadow edge',
        ];

        $at = $index % 3;

        return new self(
            subject: $this->subject,
            composition: $compositions[$at],
            action: $this->action,
            location: $this->location,
            style: $this->style,
            light: $lights[$at],
        );
    }

    /**
     * The six fields as stored, so a revision can be applied to them.
     *
     * @return array<string, string>
     */
    public function toFields(): array
    {
        return [
            'subject' => $this->subject,
            'composition' => $this->composition,
            'action' => $this->action,
            'location' => $this->location,
            'style' => $this->style,
            'light' => $this->light,
        ];
    }

    /**
     * The aspect the channel actually crops to, in words.
     *
     * Passed as well as the pixel dimensions on purpose: the dimensions tell
     * the provider what to render, and this tells the model what to leave room
     * for. A subject composed for a wide frame and delivered into a 4:5 crop
     * loses its edges, which is the second half of why the old pictures looked
     * wrong — {@see SocialImage} sends 1080×1350 to Instagram now, and a
     * composition that does not know that fills it badly.
     */
    private function ratio(ChannelPlaybook $playbook): string
    {
        $divisor = self::gcd($playbook->imageWidth, $playbook->imageHeight);

        if ($divisor === 0) {
            return 'square';
        }

        return intdiv($playbook->imageWidth, $divisor).':'.intdiv($playbook->imageHeight, $divisor);
    }

    private static function gcd(int $left, int $right): int
    {
        return $right === 0 ? $left : self::gcd($right, $left % $right);
    }

    /** @param array<string, mixed> $brief */
    private static function field(array $brief, string $key): ?string
    {
        $value = $brief[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
