<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Billing\Entitlements;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\ConnectionHealth;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * "Get your first article live", in three steps, for Home.
 *
 * The owner put it as: onboarding, webhook set, and the first publication on
 * the blog — that's it. Everything else Home says is true, but for a project
 * that has never published it is noise around the three things that matter,
 * so this is the top of the screen until the first article is live — and for
 * a day afterwards, as one line of success — and then it goes away.
 *
 * It shows during the card-free preview too: that is when a new customer
 * looks, and "choose a plan to publish" is the honest third step then.
 *
 * @phpstan-type StepState 'done'|'current'|'todo'
 * @phpstan-type Link array{label: string, href: string}
 */
final class FirstArticle
{
    /** How long the one-line success stays on Home after the first article goes live. */
    public const int CELEBRATE_HOURS = 24;

    public function __construct(
        private readonly ArticleSchedules $schedules,
        private readonly Entitlements $entitlements,
    ) {}

    /** @return array<string, mixed>|null */
    public function for(Project $project, bool $owner, ?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();
        $live = $this->firstLive($project);

        if ($live !== null) {
            [$item, $at] = $live;
            if ($at === null || $at->lt($now->copy()->subHours(self::CELEBRATE_HOURS))) {
                return null;
            }

            return ['state' => 'live', 'article' => [
                'id' => $item->getKey(), 'title' => $item->title, 'url' => $item->public_url,
            ], 'steps' => []];
        }

        $business = $project->onboarding_status->isLive();
        $website = $this->website($project, $business);
        $publish = $this->publish($project, $owner, $website['state'] === 'done', $business);
        $steps = [
            [
                'key' => 'business', 'label' => 'Set up your business',
                'state' => $business ? 'done' : 'current',
                'detail' => $business ? 'Avyo knows your business and plans articles for it.' : 'Tell Avyo about your business so it can plan your articles.',
                'action' => $business ? null : ['label' => 'Finish setup', 'href' => '/onboarding'],
            ],
            $website,
            $publish,
        ];

        return [
            'state' => 'in_progress',
            'done' => count(array_filter($steps, static fn (array $step): bool => $step['state'] === 'done')),
            'steps' => $steps,
        ];
    }

    /**
     * The first article that went live, and when.
     *
     * Either signal counts: the article's own state, or a delivery the
     * website accepted — a refresh can move a live article back to draft.
     *
     * @return array{0: ContentItem, 1: CarbonInterface|null}|null
     */
    private function firstLive(Project $project): ?array
    {
        $item = ContentItem::query()->where('project_id', $project->getKey())
            ->whereIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            ->orderByRaw('published_at is null desc')->orderBy('published_at')->first();
        $delivered = WebhookDelivery::query()->where('project_id', $project->getKey())
            ->whereNotNull('content_item_id')->where('status', DeliveryStatus::Delivered->value)
            ->with('contentItem')->oldest('delivered_at')->first();

        if ($item === null && $delivered?->contentItem === null) {
            return null;
        }
        // A live article with no date predates the record: long ago.
        if ($item !== null && $item->published_at === null) {
            return [$item, null];
        }
        $candidates = array_filter([
            $item === null ? null : [$item, $item->published_at],
            $delivered?->contentItem === null ? null : [$delivered->contentItem, $delivered->delivered_at ?? $delivered->updated_at],
        ]);
        usort($candidates, static fn (array $a, array $b): int => ($a[1]?->getTimestamp() ?? 0) <=> ($b[1]?->getTimestamp() ?? 0));

        return $candidates[0];
    }

    /** @return array<string, mixed> */
    private function website(Project $project, bool $business): array
    {
        $project->loadMissing('channels');
        $channel = $this->schedules->channels($project)->first()
            ?? $project->channels->firstWhere('is_enabled', true)
            ?? $project->channels->first();
        $base = ['key' => 'website', 'label' => 'Connect your website'];
        $waiting = $business ? 'current' : 'todo';

        if (! $channel instanceof Channel) {
            return [...$base, 'state' => $waiting, 'health' => null,
                'detail' => 'Avyo sends each finished article to your website. Connect it once.',
                'action' => ['label' => 'Connect your website', 'href' => '/channels']];
        }

        $health = ConnectionHealth::for($channel);

        return [...$base, 'health' => $health['state'], ...match ($health['state']) {
            'connected' => ['state' => 'done', 'detail' => "Connected to {$channel->name}.", 'action' => null],
            'testing' => ['state' => $waiting, 'detail' => 'Testing the connection…', 'action' => null],
            'failed' => ['state' => $waiting, 'detail' => $health['detail'] ?? "Avyo couldn't connect to your website.",
                'action' => ['label' => 'Fix connection', 'href' => '/channels']],
            'paused' => ['state' => $waiting, 'detail' => 'The connection to your website is switched off.',
                'action' => ['label' => 'Turn it back on', 'href' => '/channels']],
            default => ['state' => $waiting, 'detail' => $health['detail'] ?? 'Send a test so Avyo can check that your website receives articles.',
                'action' => ['label' => 'Finish connecting', 'href' => '/channels']],
        }];
    }

    /** @return array<string, mixed> */
    private function publish(Project $project, bool $owner, bool $connected, bool $business): array
    {
        $article = $this->candidate($project);
        $props = $article === null ? null : $this->schedules->props($article);
        $presentation = $props['presentation'] ?? null;
        $writing = $article !== null && in_array($article->state, [ContentItemState::Idea, ContentItemState::Queued, ContentItemState::Generating], true);
        // While a first attempt is on its way or has gone wrong, the article's
        // own status is the step: "Sending", "Retrying", "Couldn't publish".
        $underway = in_array($presentation['key'] ?? null, ['sending', 'retrying', 'delayed', 'failed'], true);

        [$reason, $fix] = match (true) {
            $underway => [null, null],
            ! $connected => ['Connect your website first.', null],
            $article === null => ["Avyo hasn't written an article yet. It will appear here when it is ready.", null],
            $writing => ['Your article is still being written.', null],
            ! $this->entitlements->for($project)->mayPublish() => ["Your plan doesn't include publishing yet.", ['label' => 'Choose a plan', 'href' => '/billing']],
            ($props['publish_now']['available'] ?? false) !== true => [$props['publish_now']['reason'] ?? null, null],
            ! $owner => ['Only the business owner can publish.', null],
            default => [null, null],
        };

        return [
            'key' => 'publish', 'label' => 'Publish your first article',
            'state' => $connected && $business ? 'current' : 'todo',
            'detail' => $article === null ? 'Your first article appears here once Avyo has written it.' : null,
            'action' => null,
            'article' => $article === null ? null : [
                'id' => $article->getKey(), 'title' => $article->title, 'presentation' => $presentation,
            ],
            'can_publish' => ! $underway && $reason === null && $article !== null,
            'reason' => $reason,
            'fix' => $fix,
        ];
    }

    /**
     * The article to put forward: one already on its way first, then the
     * finished article due soonest, then one still being written.
     */
    private function candidate(Project $project): ?ContentItem
    {
        $base = fn () => ContentItem::query()->where('project_id', $project->getKey())
            ->whereNotIn('state', [ContentItemState::Published->value, ContentItemState::Refreshing->value])
            ->with(['articleSchedule.delivery.channel', 'project.channels']);
        $publishAt = '(select publish_at from article_schedules where article_schedules.content_item_id = content_items.id limit 1)';

        return $base()->whereHas('articleSchedule', fn ($schedule) => $schedule->where('status', 'dispatching'))->oldest('created_at')->first()
            ?? $base()->whereIn('state', [ContentItemState::Draft->value, ContentItemState::Approved->value])
                ->orderByRaw("{$publishAt} is null")->orderByRaw($publishAt)->oldest('created_at')->first()
            ?? $base()->whereIn('state', [ContentItemState::Queued->value, ContentItemState::Generating->value])->oldest('created_at')->first();
    }
}
