<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Console\Commands\EngineTickCommand;
use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\SocialListen\Deduplicate;
use App\Pipelines\Steps\SocialListen\FeedPlanner;
use App\Pipelines\Steps\SocialListen\FetchFeeds;
use App\Pipelines\Steps\SocialListen\FetchMentions;
use App\Pipelines\Steps\SocialListen\FetchSearch;
use App\Pipelines\Steps\SocialListen\Normalise;
use App\Pipelines\Steps\SocialListen\StoreSignals;

/**
 * Listening, hourly (§4.1).
 *
 *   fetch_search  ──┐
 *   fetch_feeds   ──┼─ normalise → deduplicate → store_signals → feed_planner
 *   fetch_mentions──┘
 *
 * A fan-out into a funnel, and the shape is the argument. The three intakes are
 * independent — a project with feeds and no Threads connection is the normal
 * case, and so is the reverse — so they run in parallel and any of them may
 * skip without taking the run down. Everything after the fan-in is a single
 * file, because §4.1's requirement is that one subject produces one signal, and
 * that is only checkable where every intake has already arrived.
 *
 * **This is the contour that pays for itself.** §4.1 says so outright: even if
 * nothing is ever published, it hands the article planner live questions in
 * live words — "ту самую «оригинальную фактуру», которой §1 требует против
 * scaled content abuse и которую keyword-инструменты дать не могут". So the
 * last step is the one that matters, and it writes into the article pool rather
 * than into anything social.
 *
 * **It runs on its own schedule, not behind `engine:tick`.**
 * {@see EngineTickCommand::isBusy()} refuses to start
 * anything while any pipeline is running — right for a generation run that
 * takes minutes and costs money, and wrong for listening, which would then sit
 * out every hour a project happened to be writing something. §11.3 closes the
 * empty scheduler inside this feature; `routes/console.php` gives this its own
 * hourly line.
 *
 * Idempotent across an hour by construction, and that is `deduplicate`'s doing
 * rather than a property of the schedule: running it twice at noon leaves the
 * table as the first run left it, because the second run's candidates are all
 * matched against the signals the first one wrote.
 */
class SocialListenPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'social_listen';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Social listen (conversations → signals → ideas)';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [
            FetchSearch::class,
            FetchFeeds::class,
            FetchMentions::class,
            Normalise::class,
            Deduplicate::class,
            StoreSignals::class,
            FeedPlanner::class,
        ];
    }

    /**
     * Nothing required. The hourly schedule has nobody to name a term, and the
     * vocabulary comes from what the project already is (§4.1).
     *
     * @return array<string, mixed>
     */
    public function inputRules(): array
    {
        return [];
    }
}
