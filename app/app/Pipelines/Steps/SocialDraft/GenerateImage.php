<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Media\HeroImage;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Generation\IllustrateDraft;
use App\Pipelines\Steps\Generation\ResolvesUnit;
use Illuminate\Support\Str;

/**
 * The picture the default post has (§2), beside the guard rather than behind it.
 *
 * > Дефолт — один пост, одна мысль, **с картинкой**. […] Картинки обходят текст
 * > примерно на 60%.
 *
 * **Parallel with the guard, which is what §4.3's graph draws.** The two answer
 * unrelated questions — whether the text may be published, and what it looks
 * like — and putting the picture behind the guard would add its latency to
 * every post for no gain. The cost of the arrangement is real and small: a slot
 * the guard then refuses has already paid for an image. That is the trade the
 * spec's graph makes, and it is cheap because the asset stays on the unit: a
 * re-run of the same slot finds it through {@see HeroImage::for()} and pays
 * nothing the second time.
 *
 * **An unconfigured provider skips rather than fails**, exactly as
 * {@see IllustrateDraft} decided for articles: a project with no image key
 * should still get its writing, and a post is more of an article's situation
 * than less — an unillustrated post is a slightly weaker post, and failing the
 * run over one loses the post entirely. A provider that is configured and
 * refuses — an empty balance — is the same situation and takes the same branch.
 */
class GenerateImage extends AbstractStep
{
    use ResolvesUnit;

    /**
     * How much of the post the image prompt is built from.
     *
     * The first segment only: §2's shape is one thought with a picture of it,
     * and a chain's later segments are the argument rather than the subject.
     */
    private const int PROMPT_CHARS = 300;

    public function __construct(private readonly HeroImage $hero) {}

    public static function key(): string
    {
        return 'generate_image';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        // The chosen candidate, because the picture is of the post that won
        // rather than of the slot's title — eight candidates in three shapes
        // are not eight illustrations of the same thing.
        return [RankCandidates::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(RankCandidates::key())) {
            return StepResult::skip('Nothing was ranked for this slot, so there is nothing to illustrate.');
        }

        $chosen = $context->output(RankCandidates::key(), ChosenCandidatePayload::class);

        if (! $chosen->wasChosen() || $chosen->candidate === null) {
            return StepResult::skip('No candidate cleared the week\'s selection bar, so there is nothing to illustrate.');
        }

        if (! $this->hero->isConfigured()) {
            return StepResult::skip('No image provider is configured for this project.');
        }

        $unit = $this->unit($context);
        $subject = Str::limit($chosen->candidate->segments[0] ?? $unit->title, self::PROMPT_CHARS);

        try {
            $made = $this->hero->for($unit, $unit->title, $subject);
        } catch (TerminalStepFailure $e) {
            // A configured provider that will not draw is the same situation as
            // one that was never configured, and the answer is the same: the
            // post still ships. Only terminal failures — a timeout or a 500 is
            // worth the retry the engine would otherwise have spent.
            $context->remember('social_draft.image_stopped_because', $e->getMessage());

            return StepResult::skip("The image provider refused: {$e->getMessage()}");
        }

        if ($made === null) {
            return StepResult::skip('No image provider is configured for this project.');
        }

        return StepResult::success(new PostImagePayload(
            assetId: (string) $made['asset']->getKey(),
            costMicros: $made['cost'],
        ));
    }
}
