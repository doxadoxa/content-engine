<?php

declare(strict_types=1);

namespace App\Media;

use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Generation\IllustrateDraft;

/**
 * A media write the disk refused.
 *
 * **Terminal, and that is the interesting part.** Waiting does not fix a bucket
 * that will not take the object often enough to be worth what a retry costs
 * here, because the retry does not resume — it starts the step again from the
 * top, and the top is a paid image generation.
 *
 * This was retryable for two commits and the two things it broke are the
 * argument. `SocialImage::variants()` persists each variant as it is made, so a
 * write failing on the third left the first two committed and the retry drew
 * them again: a second charge and duplicate candidates on the draft, which is a
 * wrong draft rather than merely an expensive one. And every image step meters
 * its spend only after the picture is safely stored, so each re-drawn attempt
 * was paid for and recorded nowhere.
 *
 * Terminal instead, which every caller already knows how to survive: the hero
 * catches in {@see IllustrateDraft} and
 * ContentStudioAssistant both degrade to a post that ships without its picture,
 * and {@see CarouselPanels} skips the panel. That is this codebase's settled
 * answer to a picture that will not come — "an unillustrated draft is a weaker
 * draft; a failed batch is no drafts at all" — and a storage failure is the
 * same situation arriving one step later.
 *
 * What is given up is real: a bucket that blinked for ten seconds now costs
 * that picture rather than recovering it. Buying that back means resuming
 * *after* the generation rather than before it, which is a change to how steps
 * checkpoint and not something a exception class can decide.
 *
 * A caught write failure leaves no Asset row behind, which is the whole point
 * of raising it at all.
 */
final class MediaWriteFailed extends TerminalStepFailure
{
    /**
     * What the provider had already been paid before the disk refused, when a
     * provider was involved at all.
     *
     * The picture is generated and charged for, and only then written. Raising
     * on the write meant the GeneratedImage that carries the cost was never
     * constructed, so the money left the account and no cost row recorded it —
     * a spend that is invisible is worse than one that is merely wasted, since
     * §6's reports are how anybody would notice this happening at all.
     */
    public function __construct(
        string $message,
        public readonly ?string $spendProvider = null,
        public readonly ?string $spendModel = null,
        public readonly ?int $spendMicros = null,
    ) {
        parent::__construct($message);
    }

    /** The same failure, knowing what it cost. */
    public function withSpend(string $provider, string $model, int $micros): self
    {
        return new self($this->getMessage(), $provider, $model, $micros);
    }

    public function wasPaidFor(): bool
    {
        return $this->spendProvider !== null
            && $this->spendModel !== null
            && $this->spendMicros !== null;
    }
}
