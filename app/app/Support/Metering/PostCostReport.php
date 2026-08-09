<?php

declare(strict_types=1);

namespace App\Support\Metering;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Models\Interaction;
use App\Models\PipelineRun;
use App\Models\PipelineStep;
use App\Models\Project;
use App\Pipelines\Steps\SocialDraft\CandidatePoolPayload;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;

/**
 * §8 — what a *published* post costs, and what the four standing lines under it
 * cost.
 *
 * > Единица — **опубликованный пост**, а не сгенерированный: при отборе 1 из 8
 * > себестоимость поста в восемь раз больше стоимости одной генерации, и отчёт,
 * > считающий вызовы, соврёт в разы. Отдельными строками: слушание (дешёвое, но
 * > ежечасное и постоянное), кандидаты, картинки, ответы.
 *
 * The existing cost report answers "what did this pipeline cost", which is a
 * true number and the wrong one. §4.3 writes five to ten candidates and keeps
 * one, and it leaves slots empty on purpose; a report whose denominator is
 * model calls, or runs, or content rows touched, divides the money by something
 * that is between five and infinity times larger than the thing that actually
 * got published.
 *
 * # What lands in a post's cost
 *
 * Everything spent trying to put a post on the account, divided by the posts
 * that went on it. Concretely: every run of `social_plan` and `social_draft`,
 * plus any other run about a social unit, over the count of social units
 * published in the window.
 *
 * That fold is the whole claim. The seven candidates that lost are already
 * inside their run's `draft_candidates` step, so they arrive for free. The
 * slots that produced nothing at all arrive by the same door and for the same
 * reason: a week that drafted four slots and published one spent all four on
 * the one, and a denominator of four would report a post as costing a quarter
 * of what the channel actually paid for it. §4.3 makes the empty slot a valid
 * result, and §8 makes the operator see its price.
 *
 * # What does not
 *
 * Listening and replying are excluded from the per-post number and reported as
 * their own lines, because neither is per post. §8 asks for listening to be
 * visible as what it is — "дешёвое, но ежечасное и постоянное" — and a standing
 * hourly cost divided by a week's two or three posts is a number that moves
 * with the wrong variable: publish nothing for a fortnight and the "cost per
 * post" of a fixed hourly sweep goes to infinity. Replies are §1's other half
 * of the channel and are costed per reply.
 *
 * # Separating the post from the article
 *
 * §12's sixth exit criterion — "себестоимость опубликованного поста известна и
 * отделена от себестоимости статьи". The split is
 * {@see ContentItem::scopeSocial()} against {@see ContentItem::scopeRoots()},
 * which partition the table by type, so a run is charged to one side or the
 * other by what it was about rather than by which pipeline happened to run it.
 *
 * # Tenancy
 *
 * Every query names `project_id` and goes through `acrossProjects()`, on the
 * same reasoning as `PipelineCostCommand`: this is read from a console command
 * with no current tenant as well as from a request with one, and a scope that
 * failed closed would report that everything is free.
 */
final readonly class PostCostReport
{
    /** The step that writes the pool §8 says a post is really made of. */
    public const string CANDIDATES_STEP = 'draft_candidates';

    public const string IMAGE_STEP = 'generate_image';

    /** Hourly, cheap, and never per post. */
    public const string LISTEN_PIPELINE = 'social_listen';

    /** §1's other half of the channel: costed per reply, not per post. */
    public const string ENGAGE_PIPELINE = 'social_engage';

    /**
     * The pipelines whose entire spend exists to put a post on the account.
     *
     * `social_plan` is in here as well as `social_draft` because the week's
     * planning is part of what a post cost — it is the step that decided the
     * slot existed — and leaving it out would understate every post by the
     * price of the decision to write it.
     *
     * @var list<string>
     */
    public const array POST_PIPELINES = ['social_plan', 'social_draft'];

    /**
     * @param  array<string, mixed>  $post  the §8 unit: spend over published posts
     * @param  array<string, mixed>  $article  the same arithmetic for §12's other half
     * @param  list<array<string, mixed>>  $lines  listening, candidates, images, replies
     */
    private function __construct(
        public int $windowDays,
        public array $post,
        public array $article,
        public array $lines,
    ) {}

    /**
     * Read the window for one project.
     *
     * `$until` defaults to now. Both bounds are compared against `created_at`
     * on the run and `published_at` on the unit, which is deliberately not the
     * same instant for a given post: a post drafted on Friday and published on
     * Monday puts its cost in one window and its publication in the next. Over
     * any window longer than the drafting lead time that washes out, and the
     * alternative — chasing each published post back to the runs that made it —
     * would drop every penny spent on the slots that never published, which is
     * the half of §8's arithmetic that makes the number surprising.
     */
    public static function for(
        Project $project,
        DateTimeInterface $since,
        ?DateTimeInterface $until = null,
    ): self {
        $projectId = (string) $project->getKey();
        $end = CarbonImmutable::instance($until ?? now());
        $start = CarbonImmutable::instance($since);
        $days = max(1, (int) round($start->diffInDays($end)));

        $postSpend = self::spend(self::postRuns($projectId, $start, $end));
        $articleSpend = self::spend(self::articleRuns($projectId, $start, $end));

        $publishedPosts = self::published($projectId, $start, $end, social: true);
        $publishedArticles = self::published($projectId, $start, $end, social: false);

        $candidates = self::stepLine($projectId, $start, $end, self::CANDIDATES_STEP);
        $images = self::stepLine($projectId, $start, $end, self::IMAGE_STEP);
        $listening = self::spend(self::pipelineRuns($projectId, $start, $end, self::LISTEN_PIPELINE));
        $listeningRuns = self::pipelineRuns($projectId, $start, $end, self::LISTEN_PIPELINE)->count();
        $replies = self::spend(self::pipelineRuns($projectId, $start, $end, self::ENGAGE_PIPELINE));
        $repliesDrafted = self::pipelineRuns($projectId, $start, $end, self::ENGAGE_PIPELINE)->count();

        return new self(
            windowDays: $days,
            post: [
                'published' => $publishedPosts,
                'cost_micros' => $postSpend,
                'average_micros' => self::divide($postSpend, $publishedPosts),
                // The two halves of §8's opening sentence, side by side: how
                // many posts were written and how many went out.
                'candidates' => $candidates['units'],
                'candidates_per_post' => $publishedPosts === 0
                    ? null
                    : round($candidates['units'] / $publishedPosts, 1),
                // What one generation cost, which is the number a report that
                // counted calls would have printed as the price of a post.
                'per_generation_micros' => self::divide($candidates['cost_micros'], $candidates['units']),
            ],
            article: [
                'published' => $publishedArticles,
                'cost_micros' => $articleSpend,
                'average_micros' => self::divide($articleSpend, $publishedArticles),
            ],
            lines: [
                [
                    'key' => 'listening',
                    'label' => 'Listening',
                    'note' => 'Hourly and constant. Cheap per sweep, and the one cost that carries on '
                        .'whether or not anything is published — which is why §8 keeps it off the '
                        .'per-post average rather than hiding it inside one.',
                    'cost_micros' => $listening,
                    'units' => $listeningRuns,
                    'unit_label' => 'sweeps',
                    'per_unit_micros' => self::divide($listening, $listeningRuns),
                    'per_day_micros' => self::divide($listening, $days),
                    'per_post_micros' => null,
                    'standing' => true,
                ],
                [
                    'key' => 'candidates',
                    'label' => 'Candidates',
                    'note' => 'Five to ten posts written so that one could go out (§4.3). The losers are '
                        .'in here, which is the whole of §8: at one in eight a post costs eight '
                        .'generations, not one.',
                    'cost_micros' => $candidates['cost_micros'],
                    'units' => $candidates['units'],
                    'unit_label' => 'generations',
                    'per_unit_micros' => self::divide($candidates['cost_micros'], $candidates['units']),
                    'per_day_micros' => null,
                    'per_post_micros' => self::divide($candidates['cost_micros'], $publishedPosts),
                    'standing' => false,
                ],
                [
                    'key' => 'images',
                    'label' => 'Images',
                    'note' => 'One picture per post, drawn on the root segment only (§2). Drawn for slots '
                        .'that later came to nothing as well, and kept for the next run rather than '
                        .'paid for twice.',
                    'cost_micros' => $images['cost_micros'],
                    'units' => $images['runs'],
                    'unit_label' => 'images',
                    'per_unit_micros' => self::divide($images['cost_micros'], $images['runs']),
                    'per_day_micros' => null,
                    'per_post_micros' => self::divide($images['cost_micros'], $publishedPosts),
                    'standing' => false,
                ],
                [
                    'key' => 'replies',
                    'label' => 'Replies',
                    'note' => 'Half the value of the channel (§1, fact 2) and costed per conversation '
                        .'rather than per post. A reply is never a planned slot and never counted '
                        .'against a budget.',
                    'cost_micros' => $replies,
                    'units' => $repliesDrafted,
                    'unit_label' => 'drafts',
                    'per_unit_micros' => self::divide($replies, $repliesDrafted),
                    'per_day_micros' => null,
                    'per_post_micros' => null,
                    'standing' => false,
                    'answered' => Interaction::acrossProjects()
                        ->where('project_id', $projectId)
                        ->whereBetween('answered_at', [$start, $end])
                        ->count(),
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'window_days' => $this->windowDays,
            'post' => $this->post,
            'article' => $this->article,
            'lines' => $this->lines,
        ];
    }

    /**
     * Runs that exist to put a post on the account.
     *
     * The two social post pipelines by name, plus any other run about a social
     * unit — a repurposed derivative, or a slot re-drafted by hand. Listening
     * and replying are excluded explicitly rather than by omission, because a
     * listening run that ever gained a `content_item_id` would otherwise be
     * charged to posts *and* reported as a standing line, and double counting
     * inside one report is worse than either reading alone.
     *
     * @return Builder<PipelineRun>
     */
    private static function postRuns(string $projectId, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return self::runs($projectId, $start, $end)
            ->whereNotIn('pipeline', [self::LISTEN_PIPELINE, self::ENGAGE_PIPELINE])
            ->where(function (QueryBuilder $query) use ($projectId): void {
                $query
                    ->whereIn('pipeline', self::POST_PIPELINES)
                    ->orWhereIn('content_item_id', self::units($projectId, social: true));
            });
    }

    /**
     * Runs that exist to put an article on the site — §12's other half.
     *
     * Asked of the unit's type rather than of the pipeline, because the two
     * scopes partition `content_items` and a pipeline list would need editing
     * every time one is added. A run about nothing in particular — a research
     * sweep, a visibility probe — is in neither total, and that is correct: it
     * is not the cost of a unit and dividing it into one would make the two
     * halves of the criterion add up to more than was spent.
     *
     * @return Builder<PipelineRun>
     */
    private static function articleRuns(string $projectId, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return self::runs($projectId, $start, $end)
            ->whereNotIn('pipeline', [self::LISTEN_PIPELINE, self::ENGAGE_PIPELINE])
            ->whereNotIn('pipeline', self::POST_PIPELINES)
            ->whereIn('content_item_id', self::units($projectId, social: false));
    }

    /**
     * @return Builder<PipelineRun>
     */
    private static function pipelineRuns(
        string $projectId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $pipeline,
    ): Builder {
        return self::runs($projectId, $start, $end)->where('pipeline', $pipeline);
    }

    /**
     * @return Builder<PipelineRun>
     */
    private static function runs(string $projectId, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return PipelineRun::acrossProjects()
            ->where('project_id', $projectId)
            ->whereBetween('created_at', [$start, $end]);
    }

    /**
     * The ids of this project's posts, or of everything that is not a post.
     *
     * @return Builder<ContentItem>
     */
    private static function units(string $projectId, bool $social): Builder
    {
        $query = ContentItem::acrossProjects()->where('project_id', $projectId);

        return ($social ? $query->social() : $query->roots())->select('id');
    }

    /** @param  Builder<PipelineRun>  $runs */
    private static function spend(Builder $runs): int
    {
        return (int) $runs->sum('cost_micros');
    }

    /**
     * One line read off the step rows, with the pool size §8 wants beside it.
     *
     * `calls` is written by {@see CandidatePoolPayload}
     * and is the number of candidates the step actually generated, which is not
     * the same as the number of steps that ran — one step writes eight posts.
     * Counting steps here is exactly the lie §8 warns about, one level down.
     *
     * @return array{cost_micros: int, runs: int, units: int}
     */
    private static function stepLine(
        string $projectId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        string $stepKey,
    ): array {
        /** @var object{cost_micros: string|null, runs: int, calls: string|null}|null $row */
        $row = PipelineStep::acrossProjects()
            ->where('project_id', $projectId)
            ->where('step_key', $stepKey)
            ->whereIn('pipeline_run_id', self::runs($projectId, $start, $end)->select('id'))
            ->toBase()
            ->selectRaw('sum(cost_micros) as cost_micros, count(*) as runs')
            // `nullif` before the cast, because a step that stored no pool at
            // all has no `calls` key and `->>` answers null rather than ''.
            ->selectRaw("coalesce(sum(nullif(output->>'calls', '')::int), 0) as calls")
            ->first();

        $runs = (int) ($row->runs ?? 0);
        $calls = (int) ($row->calls ?? 0);

        return [
            'cost_micros' => (int) ($row->cost_micros ?? 0),
            'runs' => $runs,
            // A step that ran and recorded no pool still generated something,
            // so it counts as one rather than as nothing — otherwise a missing
            // output column would divide by zero and report a free post.
            'units' => $calls > 0 ? $calls : $runs,
        ];
    }

    /** How many units of this kind actually went live inside the window. */
    private static function published(
        string $projectId,
        CarbonImmutable $start,
        CarbonImmutable $end,
        bool $social,
    ): int {
        $query = ContentItem::acrossProjects()
            ->where('project_id', $projectId)
            ->where('state', ContentItemState::Published)
            ->whereBetween('published_at', [$start, $end]);

        return ($social ? $query->social() : $query->roots())->count();
    }

    /** Null rather than zero when there is nothing to divide by. */
    private static function divide(int $micros, int $by): ?int
    {
        return $by <= 0 ? null : intdiv($micros, $by);
    }
}
