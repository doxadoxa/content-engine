<?php

declare(strict_types=1);

namespace App\Ai\Assistant;

use App\ContentStudio\ContentStudioAction;
use App\ContentStudio\ContentStudioOperations;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PostKind;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\VisibilityReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use LarAgent\Tool;
use Throwable;

/**
 * What the assistant can find out, and what it can set going.
 *
 * These are the engine's own capabilities with a description attached, not a
 * second implementation of them: every write here goes through the same runner
 * and the same models the screens use, so a thing the assistant starts is
 * indistinguishable afterwards from a thing a person started by hand.
 *
 * # The line the write tools do not cross
 *
 * Every one of them stops at a draft. Nothing here approves, schedules or
 * publishes, and that is not a limitation to be lifted later — §4.2 makes a
 * person the only thing that can put words in front of an audience, and an
 * assistant that could publish would be a way around the rule rather than a
 * user of it. The model is told this in {@see AssistantInstructions} so it does
 * not promise what it cannot do.
 *
 * # Why the read tools are worth as much as the write ones
 *
 * A marketing conversation that cannot look anything up is two people guessing.
 * The read tools are what let "how do we get found for door cleaning" be
 * answered with this project's actual visibility and this project's actual
 * plan, rather than with generic advice a model could give about any business.
 */
final class MarketingTools
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly PipelineRunner $runner,
        private readonly ContentStudioOperations $operations,
        private readonly MonthPlanner $planner,
    ) {}

    /**
     * @return list<Tool>
     */
    public function all(): array
    {
        return [
            $this->readVisibility(),
            $this->readContentState(),
            $this->readBrandBrief(),
            $this->writePost(),
            $this->writeArticle(),
            $this->planMonth(),
        ];
    }

    // ------------------------------------------------------------- looking

    private function readVisibility(): Tool
    {
        return Tool::create(
            'read_visibility',
            'How often this brand appears in AI assistant answers, overall and per locale. '
            .'Use before advising on GEO, and before claiming the brand is or is not being recommended.',
        )->setCallback(function (): array {
            $report = VisibilityReport::latest();

            return [
                // Null all the way out. "You are in none of the answers" and
                // "nothing has been asked yet" are opposite facts, and a model
                // handed a 0 for the second will tell somebody their visibility
                // collapsed.
                'share_of_answers_percent' => $report->score(),
                'monitored_prompts' => $report->monitoredPrompts(),
                'prompts_answered' => $report->answered(),
                'brand_mentions' => $report->mentions(),
                'last_measured_on' => $report->lastAskedOn?->toDateString(),
                'by_locale' => $report->byLocale(),
            ];
        });
    }

    private function readContentState(): Tool
    {
        return Tool::create(
            'read_content_state',
            'What this project has in each half of the engine: articles for search, and social posts. '
            .'Counts by state, plus the most recent titles. Use before proposing new work, so the advice '
            .'accounts for what is already written and waiting.',
        )->setCallback(function (): array {
            $articles = ContentItem::query()->roots();
            $social = ContentItem::query()->social();

            // Typed to the model rather than to `Builder`, because `inState()`
            // is a local scope and static analysis cannot see it on the generic
            // builder — the same reason the scopes exist at all is that "is
            // this an article or a post" should be answerable in SQL.
            $count = static fn (Builder $query, ContentItemState $state): int => (clone $query)
                ->where('state', $state->value)->count();

            return [
                'articles' => [
                    'planned' => $count($articles, ContentItemState::Idea),
                    'drafted' => $count($articles, ContentItemState::Draft),
                    'approved_not_published' => $count($articles, ContentItemState::Approved),
                    'published' => $count($articles, ContentItemState::Published),
                    'recent_titles' => (clone $articles)->latest()->limit(10)
                        ->pluck('title')->all(),
                    'last_planning_run_on' => PipelineRun::query()
                        ->where('pipeline', 'planning')
                        ->latest('created_at')->value('created_at')?->toDateString(),
                ],
                'social' => [
                    'planned' => $count($social, ContentItemState::Idea),
                    'drafted' => $count($social, ContentItemState::Draft),
                    'approved_not_published' => $count($social, ContentItemState::Approved),
                    'published' => $count($social, ContentItemState::Published),
                    'recent_titles' => (clone $social)->latest()->limit(10)
                        ->pluck('title')->all(),
                ],
            ];
        });
    }

    private function readBrandBrief(): Tool
    {
        return Tool::create(
            'read_brand_brief',
            'The voice, offer and audience every piece of work is written from. '
            .'Read it before writing anything, and before advising on tone or positioning.',
        )->setCallback(function (): array {
            $project = $this->project();
            $brief = BrandBrief::activeFor($project);

            if ($brief === null) {
                return [
                    'exists' => false,
                    'note' => 'No brand brief has been written yet, so the engine is guessing at the voice.',
                ];
            }

            return [
                'exists' => true,
                'brief' => $brief->only(['audience', 'voice', 'offer', 'positioning', 'forbidden']),
                'site_analysis' => $project->site_analysis,
            ];
        });
    }

    // -------------------------------------------------------------- making

    private function writePost(): Tool
    {
        $kinds = implode(', ', array_column(PostKind::cases(), 'value'));

        $tool = Tool::create(
            'write_post',
            'Write one social post now. The kind decides which channels it goes to, so it is a real choice: '
            ."one of {$kinds}. Produces drafts and their pictures; it does not publish, and a person still "
            .'approves it. Ask what the point of the post is before calling this if it is not clear.',
        );

        $tool->addProperty('thesis', 'string', 'The one thing a reader should take away. A sentence, not a title.');
        $tool->addProperty('kind', 'string', 'Which shape of post this is.', array_column(PostKind::cases(), 'value'));
        $tool->setRequiredProps(['thesis', 'kind']);

        return $tool->setCallback(function (string $thesis, string $kind): array {
            $project = $this->project();
            $postKind = PostKind::tryFrom($kind);

            if ($postKind === null) {
                return ['ok' => false, 'error' => "There is no post kind called {$kind}."];
            }

            $date = CarbonImmutable::now($project->timezone)->startOfDay();
            $plan = ContentPlan::query()->firstOrCreate(['month' => $date->startOfMonth()->toDateString()]);
            $title = Str::limit(trim(Str::before($thesis, "\n")), 80, '', preserveWords: true);

            $idea = $plan->contentIdeas()->create([
                'proposal_version' => $plan->assistant_version,
                'idea_key' => Str::slug($title).'-'.Str::lower(Str::random(4)),
                'title' => $title,
                'kind' => $postKind,
                'pillar' => 'Operator',
                'thesis' => trim($thesis),
                'evidence' => [],
                'goal' => 'operator',
                'audience' => (string) ($project->site_analysis['audience'] ?? ''),
                'angle' => null,
                'channels' => array_map(
                    static fn ($channel): string => $channel->value,
                    $postKind->channels(),
                ),
                'scheduled_for' => $date,
            ]);

            // Through the studio's own operations service and not the runner:
            // it supplies `content_plan_id`, which the pipeline requires, and
            // it holds the per-idea lock that stops one idea being drafted
            // twice. Calling the runner directly failed validation every time
            // and reported it to the model as though the engine had refused.
            return $this->started(
                fn () => $this->operations->start(
                    $project,
                    $plan,
                    ContentStudioAction::GenerateIdea,
                    ['content_idea_id' => (string) $idea->getKey()],
                ),
                [
                    'ok' => true,
                    'wrote' => 'post',
                    'title' => $title,
                    'channels' => $idea->channels,
                    'note' => 'Drafting now. It lands on the social board and waits for a person.',
                ],
            );
        });
    }

    private function writeArticle(): Tool
    {
        $tool = Tool::create(
            'write_article',
            'Write one article for search now, from a topic or a question a customer would type. '
            .'Produces a draft with its GEO layer; it does not publish. Use for a single piece; '
            .'use plan_month when a whole month of topics is wanted.',
        );

        $tool->addProperty('topic', 'string', 'The question or search a reader would type. Plain words, not a headline.');
        $tool->setRequiredProps(['topic']);

        return $tool->setCallback(function (string $topic): array {
            $project = $this->project();

            // Bounded here, not only on the HTTP route. `target_query` and
            // `title` are both `varchar(255)`, and a model handed a long
            // sentence would put the insert past the column and take the whole
            // turn down with a database error. The request path validates
            // `max:255`; a tool is a second door into the same table and needs
            // its own lock.
            $query = Str::limit(trim($topic), 240, '', preserveWords: true);

            if (mb_strlen($query) < 3) {
                return ['ok' => false, 'error' => 'That topic is too short to write about.'];
            }

            $item = ContentItem::query()->create([
                'locale' => $project->default_locale,
                'type' => ContentItemType::Explainer,
                'slug' => Str::slug(Str::limit($query, 60, '')).'-'.Str::lower(Str::random(4)),
                'title' => Str::ucfirst($query),
                'target_query' => $query,
            ]);

            return $this->started(
                fn () => $this->runner->start('generation', $project, [], $item->getKey()),
                [
                    'ok' => true,
                    'wrote' => 'article',
                    'title' => $item->title,
                    'target_query' => $query,
                    'content_item_id' => (string) $item->getKey(),
                    'note' => 'Writing now. It lands in the content plan and waits for a person.',
                ],
                fn () => $item->delete(),
            );
        });
    }

    private function planMonth(): Tool
    {
        return Tool::create(
            'plan_month',
            'Choose a month of article topics out of the keyword research and write them up as a plan. '
            .'Takes a few minutes. Refused while one is already running.',
        )->setCallback(function (): array {
            // Through the same starter the button uses, so the check and the
            // start happen under one lock. Two callers each doing their own
            // `exists()` first is exactly how a month gets planned twice — and
            // a model presses faster than a person.
            try {
                $run = $this->planner->start($this->project());
            } catch (Throwable $e) {
                return ['ok' => false, 'error' => 'The planner could not start: '.$e->getMessage()];
            }

            return $run === null
                ? ['ok' => false, 'error' => 'A month is already being planned.']
                : ['ok' => true, 'started' => 'planning', 'note' => 'Planning the month. This takes a few minutes.'];
        });
    }

    // -------------------------------------------------------------- shared

    /**
     * Start the work, and say so honestly if it would not start.
     *
     * A tool that swallows the failure and reports success teaches the model to
     * tell somebody their article is being written when nothing is. The
     * compensation runs first so a half-made unit does not survive the failure
     * that stopped it being written.
     *
     * @param  callable(): mixed  $start
     * @param  array<string, mixed>  $success
     * @param  (callable(): mixed)|null  $compensate
     * @return array<string, mixed>
     */
    private function started(callable $start, array $success, ?callable $compensate = null): array
    {
        try {
            $start();
        } catch (Throwable $e) {
            if ($compensate !== null) {
                $compensate();
            }

            return ['ok' => false, 'error' => 'The engine could not start that: '.$e->getMessage()];
        }

        return $success;
    }

    private function project(): Project
    {
        $project = $this->current->get();

        if ($project === null) {
            throw new AssistantException('There is no project in scope to work on.');
        }

        return $project;
    }
}
