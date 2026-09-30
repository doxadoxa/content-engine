<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Billing\Entitlements;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\ProjectStatus;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\Pages\PageReceiverClient;
use App\Publishing\PublishToChannels;
use App\Publishing\WebhookPublisher;
use App\Support\Engine\ArticleWorkflow;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ArticleSchedules
{
    /** Why an automatic schedule on a channel that may not publish by itself is waiting. */
    public const string AWAITING_AUTOPUBLISH = 'Enable automatic publishing for this website or choose review first.';

    public function __construct(private readonly ChannelPublisherRegistry $publishers, private readonly Entitlements $entitlements) {}

    /** Only new work created after a recorded project opt-in can inherit automation. */
    public function scheduleNew(ContentItem $item, CarbonInterface $publishAt, ?Channel $target = null): ?ArticleSchedule
    {
        $item->loadMissing('project');
        $project = $item->project;
        $optedAt = $project->onboarding['article_automation_started_at'] ?? null;
        if (! is_string($optedAt)
            || $item->created_at->lt(CarbonImmutable::parse($optedAt))) {
            return null;
        }

        return DB::transaction(function () use ($item, $project, $publishAt, $target): ArticleSchedule {
            Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = ArticleSchedule::query()->where('content_item_id', $item->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $targets = $this->channels($project);
            if ($project->autopublish) {
                $targets = $targets->where('autopublish', true);
            }
            $target ??= $targets->count() === 1 ? $targets->first() : null;
            if ($target !== null && ($target->project_id !== $project->id || ! $this->compatible($target) || ($project->autopublish && ! $target->autopublish))) {
                $target = null;
            }
            $local = CarbonImmutable::instance($publishAt)->setTimezone($project->timezone);

            return ArticleSchedule::query()->create([
                'content_item_id' => $item->id, 'channel_id' => $target?->id,
                'publish_at' => $local->utc(), 'local_date' => $local->toDateString(), 'local_time' => $local->format('H:i'),
                'timezone' => $project->timezone, 'mode' => $project->autopublish ? 'automatic' : 'review_first', 'origin' => 'engine',
                'status' => $target === null ? 'blocked' : 'active', 'version' => 1,
                'blocked_reason' => $target === null ? ($project->autopublish ? 'Choose a verified website with automatic publishing enabled.' : 'Choose a verified website for this review-first schedule.') : null,
            ]);
        });
    }

    /** @param array{expected_version: int|null, local_date: string, local_time: string, mode: string, channel_id: string} $input */
    public function save(User $actor, ContentItem $item, array $input): ArticleSchedule
    {
        abort_unless($actor->projects()->whereKey($item->project_id)->wherePivot('role', 'owner')->exists(), 403);

        return $this->mutate($item, function (?ArticleSchedule $schedule) use ($actor, $item, $input): ArticleSchedule {
            $this->version($schedule, $input['expected_version']);
            $this->require($schedule?->status !== 'completed', 'This article was already published.');
            $this->require(! $item->state->isLive(), 'A published article cannot receive a new initial publication schedule.');
            $channel = Channel::query()->whereKey($input['channel_id'])->firstOrFail();
            $this->require($channel->project_id === $item->project_id && $this->compatible($channel), 'Choose an enabled website connection with a successful article publishing test.');
            $this->require(in_array($input['mode'], ['automatic', 'review_first'], true), 'Choose automatic or review first.');
            $at = $this->localTime($input['local_date'], $input['local_time'], $item->project->timezone);
            $this->require($at->isFuture(), 'Choose a future publication time.');
            $schedule ??= new ArticleSchedule(['content_item_id' => $item->id, 'version' => 0]);
            $this->withdrawQueued($schedule);
            $schedule->fill([
                'channel_id' => $channel->id, 'publish_at' => $at, 'local_date' => $input['local_date'],
                'local_time' => $input['local_time'], 'timezone' => $item->project->timezone,
                'mode' => $input['mode'], 'status' => $input['mode'] === 'automatic' && ! $channel->autopublish ? 'blocked' : 'active',
                'origin' => 'manager', 'requested_by' => $actor->id, 'version' => $schedule->version + 1,
                'blocked_reason' => $input['mode'] === 'automatic' && ! $channel->autopublish ? self::AWAITING_AUTOPUBLISH : null, 'delivery_id' => null,
            ])->save();

            return $schedule;
        });
    }

    public function change(ContentItem $item, int $expectedVersion, string $action): ArticleSchedule
    {
        return $this->mutate($item, function (?ArticleSchedule $schedule) use ($expectedVersion, $action): ArticleSchedule {
            $this->version($schedule, $expectedVersion);
            abort_if($schedule === null, 404);
            $this->require(in_array($action, ['pause', 'resume', 'cancel'], true), 'Unknown scheduling action.');
            $this->require($schedule->status !== 'completed', 'This article was already published.');
            if ($action === 'resume') {
                $this->require($schedule->publish_at->isFuture(), 'The original time has passed. Choose a new publication time.');
            }
            $this->withdrawQueued($schedule);
            $schedule->forceFill([
                'status' => match ($action) {
                    'pause' => 'paused', 'cancel' => 'canceled', default => 'active'
                },
                'version' => $schedule->version + 1, 'blocked_reason' => null, 'delivery_id' => null,
            ])->save();

            return $schedule;
        });
    }

    /**
     * Bring a channel's waiting automatic schedules in line with its switch.
     *
     * A schedule saved while the switch was off is stored as blocked, with
     * the reason written on it, and nothing looked at it again when the
     * switch went on: the article kept saying "enable automatic publishing"
     * next to a channel where it was enabled, until its date arrived and
     * `dispatch()` finally re-checked. The other direction is the same lie
     * the other way round — "Scheduled" for an article that will block.
     *
     * Only schedules nothing has been sent for, and only the block this
     * switch is responsible for: any other reason is still true.
     */
    public function followChannelAutopublish(Channel $channel): void
    {
        $waiting = ArticleSchedule::query()->where('channel_id', $channel->getKey())
            ->where('mode', 'automatic')->whereNull('delivery_id');

        $bump = ['version' => DB::raw('version + 1'), 'updated_at' => now()];

        if ($channel->autopublish) {
            $waiting->where('status', 'blocked')->where('blocked_reason', self::AWAITING_AUTOPUBLISH)
                ->update(['status' => 'active', 'blocked_reason' => null, ...$bump]);

            return;
        }

        $waiting->where('status', 'active')
            ->update(['status' => 'blocked', 'blocked_reason' => self::AWAITING_AUTOPUBLISH, ...$bump]);
    }

    /** Attach a newly verified default only to already-authorized inherited schedules. */
    public function resolveTargets(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $fresh = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $channels = $this->channels($fresh);
            foreach (['automatic', 'review_first'] as $mode) {
                $eligible = $mode === 'automatic' ? $channels->where('autopublish', true) : $channels;
                if ($eligible->count() !== 1) {
                    continue;
                }
                $target = $eligible->first();
                assert($target instanceof Channel);
                ArticleSchedule::query()->where('origin', 'engine')->where('mode', $mode)
                    ->whereNull('channel_id')->whereIn('status', ['active', 'blocked'])->whereNull('delivery_id')
                    ->update(['channel_id' => $target->id, 'status' => 'active', 'blocked_reason' => null,
                        'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            }
        });
    }

    /** Missed engine slots require a deliberate new date; attempted receipts retain their identity. */
    public function missedAutomaticDate(ArticleSchedule $schedule, Project $project): bool
    {
        return ArticleWorkflow::usesBillingPeriod($project)
            && $schedule->origin === 'engine' && $schedule->mode === 'automatic'
            && $schedule->publish_at->setTimezone($project->timezone)->toDateString() < now($project->timezone)->toDateString()
            && ($schedule->delivery === null || ($schedule->delivery->attempts === 0 && $schedule->delivery->article_attempt_started_at === null));
    }

    /** @return list<WebhookDelivery> */
    public function dispatch(ContentItem $item): array
    {
        return DB::transaction(function () use ($item): array {
            $project = Project::query()->whereKey($item->project_id)->lockForUpdate()->firstOrFail();
            $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->lockForUpdate()->first();
            if ($schedule === null || ! in_array($schedule->status, ['active', 'blocked'], true) || $schedule->publish_at->isFuture()) {
                return [];
            }
            $item->refresh()->loadMissing('project');
            $this->entitlements->forget($project);
            $reason = null;
            if ($this->missedAutomaticDate($schedule, $project)) {
                $reason = 'This automatic publication date was missed. Choose a new date to keep articles spaced out.';
            } elseif ($project->status !== ProjectStatus::Active || ! $this->entitlements->for($project)->mayPublish()) {
                $reason = 'Publishing is paused for this project or its plan.';
            } elseif ($schedule->origin === 'engine' && $schedule->mode === 'automatic' && ! $project->autopublish) {
                $reason = 'Automatic publishing is turned off for this project.';
            }
            $channel = $schedule->channel_id === null ? null : Channel::query()->find($schedule->channel_id);
            if ($reason === null && ($channel === null || ! $this->compatible($channel)
                || ($schedule->mode === 'automatic' && ! $channel->autopublish))) {
                $reason = 'Choose a verified, enabled website connection for this schedule.';
            }
            if ($reason === null && $item->state === ContentItemState::Draft && $schedule->mode === 'automatic') {
                try {
                    app(ArticleApproval::class)->approve($item, automatic: true);
                } catch (ValidationException $exception) {
                    $reason = $exception->getMessage();
                }
            }
            if ($reason === null && $item->state !== ContentItemState::Approved) {
                $reason = $item->state === ContentItemState::Draft ? 'Review and approve this article before it can publish.' : 'The article is still being prepared.';
            }
            if ($reason !== null) {
                $schedule->forceFill(['status' => 'blocked', 'blocked_reason' => $reason])->save();

                return [];
            }
            assert($channel instanceof Channel);
            $delivery = app(PublishToChannels::class)->publishToSelected($item, $channel);
            if ($delivery === null || $delivery->status === DeliveryStatus::DeadLetter) {
                $schedule->forceFill(['status' => 'blocked', 'blocked_reason' => 'The previous delivery needs attention. It will not be repeated with a new identity.'])->save();

                return [];
            }
            $delivery->forceFill(['article_schedule_id' => $schedule->id, 'article_schedule_version' => $schedule->version])->save();
            $schedule->forceFill([
                'status' => $delivery->status === DeliveryStatus::Delivered ? 'completed' : 'dispatching',
                'delivery_id' => $delivery->id, 'blocked_reason' => null,
            ])->save();

            return [$delivery];
        });
    }

    public function compatible(Channel $channel): bool
    {
        return $channel->is_enabled && $channel->verified_at !== null
            && $this->publishers->publishes($channel->type) && $channel->hasSecret()
            && ($channel->type !== ChannelType::Webhook || trim((string) ($channel->config['endpoint'] ?? '')) !== '')
            && ($channel->type !== ChannelType::WordPress || ($channel->config['article_publishing_verified'] ?? false) === true);
    }

    /**
     * Automatic publishing for a channel whose test has just made it usable
     * for articles, when the project already chose automatic publishing and
     * this is now the one place its articles can go.
     *
     * The channel form cannot offer the switch before a test has passed, and
     * nothing offered it after — so an owner who had already said "publish
     * automatically" was told publishing needed setting up, by a checkbox
     * they had to find. Not for a second eligible channel (which one gets
     * the articles is a real choice), not for a review-first project, only
     * on the test that first makes the channel usable, and never for a
     * channel whose owner has switched it off — not even after a connection
     * change has reset it.
     *
     * `$tested` is the channel as the ping was sent. The row is read again,
     * locked, and must still hold that same connection: an owner who edited
     * or disabled it while the test was in flight has an untested channel,
     * and a pass for the old one says nothing about it.
     *
     * One transaction, so the lock lasts from the read to the write. The
     * delivery job runs outside one, and a lock taken in autocommit is gone
     * the moment its SELECT returns — an owner switching the channel off in
     * between would be overwritten, and the schedules they had just blocked
     * released. The owner's side takes the same lock (ChannelController).
     */
    public function adoptProjectAutopublish(Channel $tested): void
    {
        DB::transaction(function () use ($tested): void {
            $channel = Channel::query()->whereKey($tested->getKey())->lockForUpdate()->first();

            if ($channel === null || ! $this->sameConnection($tested, $channel)) {
                return;
            }

            $channel->loadMissing('project');
            $project = $channel->project;

            if ($channel->autopublish || $channel->autopublish_declined_at !== null || ! $project->autopublish
                || ! $this->publishers->canAutopublish($channel->type) || ! $this->compatible($channel)) {
                return;
            }

            $eligible = $project->channels()->get()->filter(fn (Channel $candidate): bool => $this->compatible($candidate));

            if ($eligible->count() !== 1) {
                return;
            }

            $channel->forceFill(['autopublish' => true])->save();
            $this->followChannelAutopublish($channel);
        });
    }

    /** @return Collection<int, Channel> */
    public function channels(Project $project): Collection
    {
        $project->loadMissing('channels');

        return $project->channels->filter(fn (Channel $channel): bool => $this->compatible($channel))->values();
    }

    /** @return array<string, mixed> */
    public function props(ContentItem $item): array
    {
        $item->loadMissing(['articleSchedule.delivery', 'project.channels']);
        $schedule = $item->articleSchedule;
        $status = match (true) {
            $item->state->isLive() => 'published',
            $schedule?->status === 'completed' => 'published',
            $schedule?->status === 'dispatching' && $schedule->delivery?->status === DeliveryStatus::DeadLetter => 'blocked',
            $schedule?->status === 'dispatching' => 'publishing',
            in_array($schedule?->status, ['paused', 'canceled'], true) => $schedule->status,
            $schedule?->status === 'blocked' => 'blocked',
            $item->state === ContentItemState::Idea => 'planned',
            in_array($item->state, [ContentItemState::Queued, ContentItemState::Generating], true) => 'writing',
            $item->state === ContentItemState::Draft && ($schedule === null || $schedule->mode === 'review_first' || $item->project->is_ymyl || ($item->factcheck['passed'] ?? false) !== true) => 'needs_review',
            $schedule !== null => 'scheduled',
            default => 'unscheduled',
        };

        return [
            'status' => $status, 'timezone' => $item->project->timezone,
            'default_mode' => $item->project->autopublish ? 'automatic' : 'review_first',
            'schedule' => $schedule === null ? null : [
                'id' => $schedule->id, 'version' => $schedule->version, 'mode' => $schedule->mode, 'status' => $schedule->status, 'delivery_id' => $schedule->delivery_id,
                'publish_at' => $schedule->publish_at->toIso8601String(), 'local_date' => $schedule->publish_at->setTimezone($item->project->timezone)->toDateString(),
                'local_time' => $schedule->publish_at->setTimezone($item->project->timezone)->format('H:i'), 'timezone' => $item->project->timezone, 'channel_id' => $schedule->channel_id,
                'blocked_reason' => $schedule->blocked_reason ?? ($status === 'blocked' ? $schedule->delivery?->error : null),
            ],
            'channels' => $this->channels($item->project)->map(fn (Channel $channel): array => [
                'id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type->value, 'autopublish' => $channel->autopublish,
            ])->all(),
            'can_schedule' => ! $item->state->isLive() && $schedule?->status !== 'completed'
                && ($schedule?->delivery === null || ($schedule->delivery->attempts === 0 && $schedule->delivery->article_attempt_started_at === null)),
        ];
    }

    /** A wall-clock time must name exactly one instant, including on DST transitions. */
    public function localTime(string $date, string $time, string $timezone): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, 'UTC');
        $this->require($wall !== null && $wall->format('Y-m-d H:i') === $date.' '.$time, 'Enter a valid publication date and time.');
        assert($wall instanceof CarbonImmutable);
        $offsets = array_unique(array_column($zone->getTransitions($wall->subDays(2)->getTimestamp(), $wall->addDays(2)->getTimestamp()) ?: [], 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $candidate = $wall->subSeconds($offset);
            if ($candidate->setTimezone($zone)->format('Y-m-d H:i') === $date.' '.$time) {
                $matches[] = $candidate;
            }
        }
        $this->require(count($matches) === 1, 'This local time is skipped or occurs twice when the clocks change. Choose another time.');

        return $matches[0];
    }

    /** Where the ping went and with what: type, enablement, secret and the destination keys. */
    private function sameConnection(Channel $tested, Channel $current): bool
    {
        $destination = static fn (Channel $channel): array => array_intersect_key(
            $channel->config,
            array_flip(['endpoint', 'page_receiver_base', 'username']),
        );

        return $tested->type === $current->type
            && $current->is_enabled
            && $tested->getRawOriginal('secret') === $current->getRawOriginal('secret')
            && PageReceiverClient::same($destination($tested), $destination($current));
    }

    /**
     * @template T
     *
     * @param  callable(?ArticleSchedule): T  $operation
     * @return T
     */
    private function mutate(ContentItem $item, callable $operation): mixed
    {
        $lock = null;
        try {
            return DB::transaction(function () use ($item, $operation, &$lock): mixed {
                Project::query()->whereKey($item->project_id)->lockForUpdate()->firstOrFail();
                $item->refresh()->loadMissing('project');
                $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->lockForUpdate()->first();
                $lock = $schedule?->delivery_id === null ? null : Cache::lock('webhook-delivery:'.$schedule->delivery_id, WebhookPublisher::lockSeconds());
                $this->require($lock === null || $lock->get(), 'A delivery is in progress. Wait for its result before changing this schedule.');

                return $operation($schedule);
            });
        } finally {
            $lock?->release();
        }
    }

    private function withdrawQueued(ArticleSchedule $schedule): void
    {
        if ($schedule->delivery_id === null) {
            return;
        }
        $delivery = WebhookDelivery::query()->findOrFail($schedule->delivery_id);
        $this->require($delivery->attempts === 0 && $delivery->article_attempt_started_at === null && $delivery->status !== DeliveryStatus::Delivered,
            'A delivery has already been attempted. Review its result before creating another publication.');
        $delivery->forceFill(['status' => DeliveryStatus::DeadLetter, 'next_attempt_at' => null,
            'error' => 'The publication schedule changed before this delivery was sent.'])->save();
        // Safe to release the content identity only when no request was attempted.
        $delivery->forceFill(['dispatch_key' => null])->save();
    }

    private function version(?ArticleSchedule $schedule, ?int $expected): void
    {
        abort_unless($schedule?->version === $expected, 409, 'This schedule changed in another window. Reload before changing it.');
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['schedule' => $message]);
        }
    }
}
