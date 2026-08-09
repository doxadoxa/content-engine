<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Console\Commands\EngineTickCommand;
use App\Console\Commands\PipelineCostCommand;
use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\SocialEngage\DraftReply;
use App\Pipelines\Steps\SocialEngage\FactCheckReply;
use App\Pipelines\Steps\SocialEngage\GuardReply;
use App\Pipelines\Steps\SocialEngage\LoadConversation;
use App\Social\Jobs\DraftInteractionReplyJob;

/**
 * Answering, on an event (§4.2).
 *
 *   load_conversation → draft_reply → fact_check_reply → guard_reply
 *
 * A straight line, and short. Everything §4.3 fans out for — candidates,
 * images, ranking — is absent because a reply is one thought written once, and
 * every extra node is a queue hop against a target measured in minutes.
 *
 * **Why this is a pipeline and not a plain job.** The tempting reading of §4.2
 * is that "минуты" argues for the least machinery possible, so a job that calls
 * a model and writes a column. Two things say otherwise.
 *
 * §8 is the first: "Отдельными строками: слушание…, кандидаты, картинки,
 * **ответы**." Metering in this engine means `pipeline_steps` rows — that is
 * what {@see PipelineCostCommand} groups over, and it is what pins a run to the
 * price list version it started under (§3.4). A plain job would have to grow
 * its own cost table for the report to find, or the replies would cost nothing
 * on paper while §12's sixth exit criterion asks for the cost of a unit to be
 * known and separated. Registered as its own pipeline key, `pipeline:cost
 * --pipeline=social_engage` is that line, for free, with the fact-check priced
 * separately from the draft inside it.
 *
 * The second is that the durability is not overhead here, it is the feature.
 * The state lives in rows, so a worker killed between the model call and the
 * write leaves a run that resumes instead of a conversation that silently never
 * got a draft — and a conversation with no draft is invisible in exactly the
 * way §7 refuses.
 *
 * The latency the machinery costs is queue hops, and there are three of them.
 * The model call is seconds; three redis round trips are milliseconds. What
 * would actually have cost minutes is the thing this pipeline is deliberately
 * *not* behind — see below.
 *
 * **It is started by an event, not by the clock.** {@see EngineTickCommand::isBusy()}
 * refuses to start anything while any run is pending or running, which is right
 * for a generation that costs money and wrong for a reply: a conversation
 * queued behind an article is a conversation answered in an hour. So there is
 * no scheduler line for this. {@see DraftInteractionReplyJob} — dispatched by
 * the webhook receiver the moment the row is written — starts a run directly.
 *
 * **Nothing in it sends anything.** The last step is {@see GuardReply}, whose
 * whole body ends at `markDrafted()`. §4.2's prohibition is not a setting this
 * pipeline honours; it is the absence of any node that could send.
 */
class SocialEngagePipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'social_engage';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Social engage (conversation → draft reply → operator)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            LoadConversation::class,
            DraftReply::class,
            FactCheckReply::class,
            GuardReply::class,
        ];
    }

    /**
     * The conversation to answer, and nothing else.
     *
     * Required rather than optional: a run started without one has nothing to
     * do, and `start()` validates before a single step is queued, so the caller
     * hears about it where it can still be fixed.
     *
     * @return array<string, mixed>
     */
    public function inputRules(): array
    {
        return ['interaction_id' => ['required', 'string']];
    }
}
