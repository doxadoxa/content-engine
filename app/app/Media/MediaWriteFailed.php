<?php

declare(strict_types=1);

namespace App\Media;

use App\Pipelines\Exceptions\RetryableStepFailure;

/**
 * A media write the disk refused.
 *
 * Its own type, and not a bare {@see RetryableStepFailure}, because the two
 * callers want opposite things from it and neither should have to catch a
 * category that wide.
 *
 * A pipeline step wants the ladder: the run is resumable, so waiting and trying
 * again is free and usually works. Extending RetryableStepFailure is what gives
 * it that, unchanged.
 *
 * The studio assistant wants to carry on. `generateIdea` persists every draft
 * before it illustrates anything and is wrapped in a lock rather than a
 * transaction, so an exception escaping illustration leaves the drafts written
 * — and the retry finds nothing missing, returns `created: 0` and never
 * illustrates. That failure is documented twice in this codebase already, once
 * on the renderer catch in {@see CarouselPanels} and once on the provider catch
 * in ContentStudioAssistant, both of which reach the same conclusion: a post
 * that is written and paid for must not be lost to a picture that would not
 * draw. Catching this type there degrades the same way, and a caught write
 * failure leaves no Asset row behind, which is the whole point of raising it.
 */
final class MediaWriteFailed extends RetryableStepFailure
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
