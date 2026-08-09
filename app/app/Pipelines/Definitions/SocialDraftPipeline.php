<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Console\Commands\EngineTickCommand;
use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\SocialDraft\DraftCandidates;
use App\Pipelines\Steps\SocialDraft\FactCheckPost;
use App\Pipelines\Steps\SocialDraft\GenerateImage;
use App\Pipelines\Steps\SocialDraft\GuardPolicy;
use App\Pipelines\Steps\SocialDraft\LoadContext;
use App\Pipelines\Steps\SocialDraft\RankCandidates;
use App\Pipelines\Steps\SocialDraft\SaveDraft;

/**
 * One slot, filled or left empty (§4.3).
 *
 *   load_context ─ draft_candidates (N) ─ rank ─┬─ guard_policy ──┐
 *                                               ├─ generate_image ┼─ save_draft
 *                                               └─ fact_check_post┘
 *
 * §4.3's graph, plus §10's node. The spec draws two branches after `rank`; the
 * third is the fact-check that §10 makes mandatory on a YMYL project — "фактчек
 * в `social_draft` — обязательный шаг, как в генерации" — and it belongs beside
 * the guard rather than inside it, because §4.3 requires `guard_policy` to be
 * model-free and a fact-check is a model call.
 *
 * **One run per slot.** The unit `social_plan` created is the input, and the run
 * carries its id, so the whole pipeline is about one post. A pipeline that
 * drafted the week would have to decide what a partial failure meant; this one
 * cannot have a partial failure, because the smallest thing it can fail at is
 * the whole of its purpose.
 *
 * **The expensive model is spent in exactly one node**, which is §4.3's own
 * claim: `draft_candidates` writes five to ten posts so that `rank` can keep
 * one, and everything downstream of it is arithmetic, a picture, and §10. That
 * shape is the argument of §1 in code — on this channel the engine is doing
 * selection rather than generation, and selection costs eight drafts.
 *
 * **Nothing in it publishes.** The last node ends at `markDrafted()`. §5 makes
 * autopublish something a band earns and §4.2 forbids it outright for two of
 * them; here it is not a setting that is off, it is a call that does not exist.
 *
 * **Started per slot rather than by the clock**, like {@see SocialEngagePipeline}
 * and unlike the six pipelines {@see EngineTickCommand} runs: a slot at 09:00 on
 * Wednesday wants its draft ready before Wednesday, and a scheduler that ran the
 * whole contour hourly would draft the same slot repeatedly. What starts it is
 * whatever knows a slot is due — which is why `content_item_id` is required
 * input rather than something this definition would go looking for.
 */
class SocialDraftPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'social_draft';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Social draft (one slot → 5–10 candidates → one post, or nothing)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            LoadContext::class,
            DraftCandidates::class,
            RankCandidates::class,
            GuardPolicy::class,
            GenerateImage::class,
            FactCheckPost::class,
            SaveDraft::class,
        ];
    }

    /**
     * The slot, and nothing else.
     *
     * Required, and validated before a step is queued: a run started without a
     * slot would spend eight expensive calls and then discover it has nowhere to
     * put the result.
     *
     * @return array<string, mixed>
     */
    public function inputRules(): array
    {
        return ['content_item_id' => ['required', 'string']];
    }
}
