<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Billing\Entitlements;
use App\Billing\Metric;
use App\Content\ArticleBusinessFacts;
use App\Content\ArticleScore;
use App\Enums\ContentItemState;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Pipelines\Steps\Planning\PlanningWindow;
use App\Publishing\ChannelPublisherRegistry;
use App\Support\Engine\ArticleWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ArticleApproval
{
    public function __construct(private readonly Entitlements $entitlements, private readonly ArticleScore $score) {}

    public function approve(ContentItem $item, bool $automatic = false): bool
    {
        return DB::transaction(function () use ($item, $automatic): bool {
            $project = Project::query()->whereKey($item->project_id)->lockForUpdate()->firstOrFail();
            $draft = ContentItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            if ($automatic) {
                $this->require($project->status === ProjectStatus::Active, 'Automatic publication is paused for this project.', BlockedCode::PROJECT_PAUSED);
                $schedule = ArticleSchedule::query()->where('content_item_id', $draft->id)->first();
                $this->require($schedule !== null && $schedule->mode === 'automatic'
                    && in_array($schedule->status, ['active', 'blocked'], true)
                    && $project->autopublish, 'This article has no active automatic schedule.', BlockedCode::NEEDS_APPROVAL);
                $this->require($schedule !== null && ! app(ArticleSchedules::class)->missedAutomaticDate($schedule, $project), 'This automatic publication date was missed. Choose a new date before approval.', BlockedCode::MISSED_DATE);
                $this->require(! $project->is_ymyl, 'This topic needs a person to review and approve the article.', BlockedCode::NEEDS_APPROVAL);
                $this->require(($draft->factcheck['passed'] ?? false) === true, 'The fact check has not passed. Review the article before publishing.', BlockedCode::FACT_CHECK);
                $channel = $schedule?->channel_id === null ? null : Channel::query()->find($schedule->channel_id);
                $this->require($channel !== null && app(ArticleSchedules::class)->compatible($channel)
                    && app(ChannelPublisherRegistry::class)->canAutopublish($channel->type), 'Connect and test your website before articles publish automatically.', BlockedCode::WEBSITE_PAUSED);
            }
            $this->require(in_array($draft->state, [ContentItemState::Draft, ContentItemState::Approved], true), 'Only a finished draft can be approved.', BlockedCode::OTHER);
            $factsRefusal = app(ArticleBusinessFacts::class)->refusal($draft);
            $this->require($factsRefusal === null, $factsRefusal ?? '', BlockedCode::BUSINESS_FACTS);
            $scored = $this->score->for($draft->loadMissing('assets'));
            $this->require($scored['publishable'], 'This draft needs attention: '.implode(', ', $scored['blocking']).'.', BlockedCode::SCORE);
            $this->entitlements->forget($project);
            $entitlement = $this->entitlements->for($project);
            $this->require($entitlement->mayPublish(), 'An active plan or available publication grace is required.', BlockedCode::PLAN);
            $recorded = DB::table('article_approval_records')->where('content_item_id', $draft->id)->exists();
            if (! $recorded) {
                // An already-approved article can enter through a historical
                // import. Adoption records it without charging it again.
                $units = $draft->state === ContentItemState::Approved || $draft->published_at !== null ? 0 : 1;
                if ($units !== 0 && ArticleWorkflow::usesBillingPeriod($project)) {
                    PlanningWindow::forProject($project);
                }
                if ($units !== 0 && ! $this->entitlements->reserve($project, Metric::Articles)) {
                    throw ArticleRefusal::because('article_allowance', 'No articles remain in this period. The draft stays saved.', BlockedCode::ALLOWANCE_USED);
                }
                DB::table('article_approval_records')->insert([
                    'id' => (string) Str::ulid(), 'project_id' => $project->id, 'content_item_id' => $draft->id,
                    'accepted_at' => $units === 1 ? now() : null, 'units' => $units,
                    'period_started_at' => $entitlement->subscription?->periodStart(),
                    'policy' => $units === 1 ? 'first_article_approval' : 'existing_article_approval',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($draft->state === ContentItemState::Draft) {
                $draft->forceFill(['review' => [], 'reviewed_at' => null])->save();
                $draft->approve();
            }
            // Who said yes. Avyo's own approval was given because nobody had
            // to look, so it stops counting the moment somebody has to — see
            // ArticleSchedules::followProject() — and a person's replaces it.
            ArticleSchedule::acrossProjects()->where('content_item_id', $draft->id)
                ->update(['approved_by_avyo' => $automatic]);
            $item->refresh();

            return true;
        });
    }

    private function require(bool $condition, string $message, string $code): void
    {
        if (! $condition) {
            throw ArticleRefusal::because('approval', $message, $code);
        }
    }
}
