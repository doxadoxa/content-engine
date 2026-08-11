<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\ContentStudio\ContentStudioAssistant;
use App\Enums\PostKind;

/**
 * What a month has to contain, as opposed to what each post has to be.
 *
 * {@see PostKind} is a property of one idea and this is a property of the set,
 * which is why it is a separate object: no amount of checking one idea at a
 * time catches a month where every idea is fine and all twenty are how-tos.
 * That was the actual state of the plan this was written for.
 *
 * **A target and a ceiling are different instruments and only one of them is
 * enforced.** Four of the five kinds carry a share that the proposal is *asked*
 * for and measured against loosely — a month with three takes instead of five
 * is a month, and refusing it would mean a plan that cannot be produced. The
 * fifth is `offer`, and that one is a hard ceiling: config/social.php argues at
 * length that "недобор допустим, перебор — нет", and nowhere is it truer than
 * here. A month that under-teaches is a weaker month; a month that over-sells
 * is the account people stop following.
 *
 * **The instruction and the check are the same numbers.** {@see instruction()}
 * is what the proposal prompt says and {@see findings()} is what the normaliser
 * enforces, both read off this object, so the model cannot be asked for one
 * month and refused for producing it.
 */
final readonly class ContentMix
{
    /**
     * @param  array<string, int>  $shares  percentage points per kind value
     */
    private function __construct(
        private array $shares,
        private int $offerCeiling,
    ) {}

    public static function fromConfig(): self
    {
        $shares = [];

        foreach (PostKind::cases() as $kind) {
            $shares[$kind->value] = (int) config("content_studio.mix.shares.{$kind->value}", 0);
        }

        return new self(
            shares: $shares,
            offerCeiling: (int) config('content_studio.mix.offer_ceiling', 10),
        );
    }

    /**
     * How many of each kind a month of this size wants.
     *
     * Rounded down, so the targets never add up to more than the month has —
     * a set of instructions that asks for twenty-one posts out of twenty is one
     * the model resolves by ignoring the part it likes least.
     *
     * @return array<string, int>
     */
    public function targets(int $ideas): array
    {
        $targets = [];

        foreach ($this->shares as $kind => $share) {
            $targets[$kind] = intdiv($ideas * $share, 100);
        }

        return $targets;
    }

    /** The most `offer` posts a month of this size may carry, and never fewer than none. */
    public function offerLimit(int $ideas): int
    {
        return intdiv($ideas * $this->offerCeiling, 100);
    }

    /**
     * The mix as a line in the proposal prompt.
     *
     * Written as counts rather than percentages because the model is producing
     * a list of that length and percentages have to be converted before they
     * can be obeyed — a conversion it does badly and silently.
     */
    public function instruction(int $ideas): string
    {
        $parts = [];

        foreach ($this->targets($ideas) as $kind => $target) {
            if ($kind === PostKind::Offer->value || $target < 1) {
                continue;
            }

            $parts[] = "about {$target} ".PostKind::from($kind)->value;
        }

        return implode(' ', [
            "A month is a mix, not a run of tips. Across these {$ideas} ideas, aim for roughly:",
            implode(', ', $parts).'.',
            sprintf(
                'At most %d of them may be an %s post — that is a ceiling and not a target, and a month '
                    .'with none is a good month.',
                $this->offerLimit($ideas),
                PostKind::Offer->value,
            ),
            'Every kind that has a target above must appear at least once.',
        ]);
    }

    /**
     * What is wrong with this month, in words the model can act on.
     *
     * Returned rather than thrown, because the caller is
     * {@see ContentStudioAssistant} and what it does with a finding is ask
     * again with the finding attached — the same correction loop the drafting
     * step already runs. An exception here would make the retry the caller's
     * problem to reconstruct.
     *
     * Only two things are refused, and both are failures of the set rather than
     * of taste: selling past the ceiling, and a month that is entirely one
     * thing. A shortfall against a target is not in here on purpose — see the
     * class docblock.
     *
     * @param  list<PostKind>  $kinds
     * @return list<string>
     */
    public function findings(array $kinds): array
    {
        if ($kinds === []) {
            return [];
        }

        $counts = array_count_values(array_map(
            static fn (PostKind $kind): string => $kind->value,
            $kinds,
        ));

        $total = count($kinds);
        $findings = [];
        $offers = $counts[PostKind::Offer->value] ?? 0;
        $limit = $this->offerLimit($total);

        if ($offers > $limit) {
            $findings[] = sprintf(
                'The month carries %d %s posts and at most %d are allowed out of %d ideas. Rewrite the '
                    .'extra ones as another kind — what you know, what you have shown, or how the work '
                    .'is actually done.',
                $offers,
                PostKind::Offer->value,
                $limit,
                $total,
            );
        }

        $missing = [];

        foreach ($this->targets($total) as $kind => $target) {
            // `offer` carries a share like the others and means the opposite by
            // it: the number is the most a month may have, so a month with none
            // is not missing anything. Reporting it here would have demanded a
            // sales post every month, from the same object whose job is to keep
            // them rare.
            if ($kind === PostKind::Offer->value) {
                continue;
            }

            if ($target >= 1 && ($counts[$kind] ?? 0) === 0) {
                $missing[] = $kind;
            }
        }

        if ($missing !== []) {
            $findings[] = sprintf(
                'The month contains no %s at all. A plan made entirely of one kind reads as one long '
                    .'article cut into pieces, which is the thing these channels punish hardest.',
                implode(' and no ', $missing),
            );
        }

        return $findings;
    }
}
