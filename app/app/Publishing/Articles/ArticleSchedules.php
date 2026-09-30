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

/**
 * When an article goes out, where to, and whether a person approves it first.
 *
 * Whether it waits for a person is one switch on the project — "Publish
 * automatically" or "Let me review first" — plus, per article, the owner's
 * "Hold this article for my review" (`held_for_review`). `mode` is what those
 * two add up to, stored so the dispatcher, the approval and the review list
 * read one column: `review_first` when the article is held or the project
 * reviews first, `automatic` otherwise. {@see followProject()} keeps it true
 * when the project's answer changes. The hold is kept apart from the mode
 * because the mode alone cannot say, once a project has gone review-first,
 * which of its articles the owner had held and which were only following.
 *
 * Websites used to carry a switch of their own, and a schedule had to agree
 * with both. Owners could not tell which of the three was stopping an
 * article, so a website is now simply usable for articles or not
 * ({@see compatible()}).
 */
final class ArticleSchedules
{
    public const string NO_WEBSITE = 'Connect and test your website so this article can publish.';

    public const string CHOOSE_WEBSITE = 'Choose which website this article should publish to.';

    public const string MISSED_DATE = 'This automatic publication date was missed. Choose a new date to keep articles spaced out.';

    public const string PAUSED = 'Publishing is paused for this project or its plan.';

    public const string WEBSITE_UNUSABLE = 'Choose a verified, enabled website connection for this schedule.';

    public const string NEEDS_APPROVAL = 'Review and approve this article before it can publish.';

    public const string STILL_WRITING = 'The article is still being prepared.';

    public const string PREVIOUS_FAILED = 'The previous delivery needs attention. It will not be repeated with a new identity.';

    public function __construct(private readonly ChannelPublisherRegistry $publishers, private readonly Entitlements $entitlements) {}

    /** What a schedule's mode is, given the project's answer and the owner's hold. */
    public static function modeFor(Project $project, bool $held): string
    {
        return $project->autopublish && ! $held ? 'automatic' : 'review_first';
    }

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
            $target ??= $targets->count() === 1 ? $targets->first() : null;
            if ($target !== null && ($target->project_id !== $project->id || ! $this->compatible($target))) {
                $target = null;
            }
            $local = CarbonImmutable::instance($publishAt)->setTimezone($project->timezone);

            return ArticleSchedule::query()->create([
                'content_item_id' => $item->id, 'channel_id' => $target?->id,
                'publish_at' => $local->utc(), 'local_date' => $local->toDateString(), 'local_time' => $local->format('H:i'),
                'timezone' => $project->timezone, 'mode' => self::modeFor($project, held: false), 'held_for_review' => false, 'origin' => 'engine',
                'status' => $target === null ? 'blocked' : 'active', 'version' => 1,
                'blocked_reason' => $target === null ? ($targets->isEmpty() ? self::NO_WEBSITE : self::CHOOSE_WEBSITE) : null,
            ]);
        });
    }

    /**
     * The owner's date, time and website for one article, and whether to hold it.
     *
     * `channel_id` may be left out when the project has exactly one usable
     * website — the form does not ask a question with one answer. `hold` left
     * out keeps whatever the article had: a review-first project does not show
     * the checkbox, and saving a new date there must not quietly release a
     * hold the owner set while the project was automatic.
     *
     * @param  array{expected_version: int|null, local_date: string, local_time: string, channel_id?: string|null, hold?: bool|null}  $input
     */
    public function save(User $actor, ContentItem $item, array $input): ArticleSchedule
    {
        abort_unless($actor->projects()->whereKey($item->project_id)->wherePivot('role', 'owner')->exists(), 403);

        return $this->mutate($item, function (?ArticleSchedule $schedule) use ($actor, $item, $input): ArticleSchedule {
            $this->version($schedule, $input['expected_version']);
            $this->require($schedule?->status !== 'completed', 'This article was already published.');
            $this->require(! $item->state->isLive(), 'A published article cannot receive a new initial publication schedule.');
            $project = $item->project;
            $usable = $this->channels($project);
            $channel = ($input['channel_id'] ?? null) === null
                ? ($usable->count() === 1 ? $usable->first() : null)
                : Channel::query()->whereKey($input['channel_id'])->first();
            $this->require($channel !== null && $channel->project_id === $item->project_id && $this->compatible($channel), 'Choose an enabled website connection with a successful article publishing test.');
            assert($channel instanceof Channel);
            $at = $this->localTime($input['local_date'], $input['local_time'], $project->timezone);
            $this->require($at->isFuture(), 'Choose a future publication time.');
            $held = $input['hold'] ?? $schedule->held_for_review ?? false;
            $schedule ??= new ArticleSchedule(['content_item_id' => $item->id, 'version' => 0]);
            $this->withdrawQueued($schedule);
            $schedule->fill([
                'channel_id' => $channel->id, 'publish_at' => $at, 'local_date' => $input['local_date'],
                'local_time' => $input['local_time'], 'timezone' => $project->timezone,
                'mode' => self::modeFor($project, $held), 'held_for_review' => $held, 'status' => 'active',
                'origin' => 'manager', 'requested_by' => $actor->id, 'version' => $schedule->version + 1,
                'blocked_reason' => null, 'delivery_id' => null,
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
     * Bring the project's waiting articles in line with its answer.
     *
     * Call it in the transaction that changed `projects.autopublish`. Before
     * this, a schedule kept the mode it was made with: an owner who switched
     * to "Let me review first" and then approved an article by hand still saw
     * it blocked as "Automatic publishing is turned off", because the schedule
     * went on asking to publish automatically.
     *
     * Only schedules nothing has been sent for, and never a held one — the
     * owner asked for that article to wait whatever the project does. A block
     * is lifted along with the change, whatever it said: every reason the
     * mode decides is now out of date, and one that is still true is written
     * again by {@see dispatch()} the next time the article is due. A schedule
     * with no website stays blocked, since that is still the whole story.
     */
    public function followProject(Project $project): void
    {
        $mode = self::modeFor($project, held: false);
        $drifted = fn () => ArticleSchedule::acrossProjects()->where('project_id', $project->getKey())
            ->where('held_for_review', false)->where('mode', '!=', $mode)
            ->whereNull('delivery_id')->whereNotIn('status', ['completed', 'dispatching']);

        $drifted()->where('status', 'blocked')->whereNotNull('channel_id')
            ->update(['status' => 'active', 'blocked_reason' => null]);
        $drifted()->update(['mode' => $mode, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    /** Give engine schedules with nowhere to go the project's one usable website, once there is one. */
    public function resolveTargets(Project $project): void
    {
        DB::transaction(function () use ($project): void {
            $fresh = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $channels = $this->channels($fresh);
            if ($channels->count() !== 1) {
                return;
            }
            $target = $channels->first();
            assert($target instanceof Channel);
            ArticleSchedule::query()->where('origin', 'engine')
                ->whereNull('channel_id')->whereIn('status', ['active', 'blocked'])->whereNull('delivery_id')
                ->update(['channel_id' => $target->id, 'status' => 'active', 'blocked_reason' => null,
                    'version' => DB::raw('version + 1'), 'updated_at' => now()]);
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
                $reason = self::MISSED_DATE;
            } elseif ($project->status !== ProjectStatus::Active || ! $this->entitlements->for($project)->mayPublish()) {
                $reason = self::PAUSED;
            }
            $channel = $schedule->channel_id === null ? null : Channel::query()->find($schedule->channel_id);
            if ($reason === null && ($channel === null || ! $this->compatible($channel))) {
                $reason = self::WEBSITE_UNUSABLE;
            }
            // Asked of the project as well as the schedule, so a schedule that
            // somehow missed followProject() waits for a person rather than
            // blocking an article a person has already approved.
            if ($reason === null && $item->state === ContentItemState::Draft && $schedule->mode === 'automatic' && $project->autopublish) {
                try {
                    app(ArticleApproval::class)->approve($item, automatic: true);
                } catch (ValidationException $exception) {
                    $reason = $exception->getMessage();
                }
            }
            if ($reason === null && $item->state !== ContentItemState::Approved) {
                $reason = $item->state === ContentItemState::Draft ? self::NEEDS_APPROVAL : self::STILL_WRITING;
            }
            if ($reason !== null) {
                $schedule->forceFill(['status' => 'blocked', 'blocked_reason' => $reason])->save();

                return [];
            }
            assert($channel instanceof Channel);
            $delivery = app(PublishToChannels::class)->publishToSelected($item, $channel);
            if ($delivery === null || $delivery->status === DeliveryStatus::DeadLetter) {
                $schedule->forceFill(['status' => 'blocked', 'blocked_reason' => self::PREVIOUS_FAILED])->save();

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

    /** Whether articles can go to this website: switched on, proven by a test, and reachable. */
    public function compatible(Channel $channel): bool
    {
        return $channel->is_enabled && $channel->verified_at !== null
            && $this->publishers->publishes($channel->type) && $channel->hasSecret()
            && ($channel->type !== ChannelType::Webhook || trim((string) ($channel->config['endpoint'] ?? '')) !== '')
            && ($channel->type !== ChannelType::WordPress || ($channel->config['article_publishing_verified'] ?? false) === true);
    }

    /** @return Collection<int, Channel> */
    public function channels(Project $project): Collection
    {
        $project->loadMissing('channels');

        return $project->channels->filter(fn (Channel $channel): bool => $this->compatible($channel))->values();
    }

    /**
     * Send one article to the project's website now, whatever its date says.
     *
     * The owner pressing "Publish now" is the approval, so a finished draft is
     * approved on the way — through {@see ArticleApproval}, so the business
     * facts, the score, the plan and the allowance are asked exactly as they
     * are for the Approve button. The schedule is then moved to this minute
     * and handed to {@see dispatch()}, which is the one path that sends an
     * article: its row locks and its `dispatching` status are what keep the
     * scheduler from sending the same article a second time afterwards.
     *
     * Refusals come back as a validation error on `publish`, in words the
     * owner can act on, and are asked before the approval so a press that
     * cannot publish does not spend an article from the allowance.
     */
    public function publishNow(User $actor, ContentItem $item): ArticleSchedule
    {
        abort_unless($actor->projects()->whereKey($item->project_id)->wherePivot('role', 'owner')->exists(), 403);
        $item->refresh()->loadMissing('project');
        $project = $item->project;
        $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->with('delivery')->first();
        $refusal = $this->publishNowRefusal($item, $schedule);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['publish' => $refusal]);
        }

        if ($item->state === ContentItemState::Draft) {
            try {
                app(ArticleApproval::class)->approve($item);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['publish' => $exception->getMessage()]);
            }
        }

        $this->mutate($item, function (?ArticleSchedule $schedule) use ($actor, $item, $project): ArticleSchedule {
            $channel = $this->target($project, $schedule);
            $sent = $schedule?->delivery_id === null ? null : WebhookDelivery::query()->find($schedule->delivery_id);
            if ($channel === null || $schedule?->status === 'completed'
                || ($schedule?->status === 'dispatching' && $sent?->status !== DeliveryStatus::DeadLetter)) {
                throw ValidationException::withMessages(['publish' => 'This article changed in another window. Reload the page and try again.']);
            }
            $at = CarbonImmutable::now()->setTimezone($project->timezone);
            $schedule ??= new ArticleSchedule(['content_item_id' => $item->id, 'version' => 0]);
            $this->withdrawQueued($schedule);
            $schedule->fill([
                'channel_id' => $channel->id, 'publish_at' => $at->utc(), 'local_date' => $at->toDateString(),
                'local_time' => $at->format('H:i'), 'timezone' => $project->timezone,
                'mode' => self::modeFor($project, held: false), 'held_for_review' => false, 'status' => 'active',
                'origin' => 'manager', 'requested_by' => $actor->id, 'version' => $schedule->version + 1,
                'blocked_reason' => null, 'delivery_id' => null,
            ])->save();

            return $schedule;
        });

        $this->dispatch($item);
        $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->firstOrFail();
        if ($schedule->status === 'blocked') {
            $item->unsetRelation('articleSchedule');
            $presentation = PublicationStatus::for($item->refresh(), $schedule->load('delivery'));

            throw ValidationException::withMessages(['publish' => $presentation['detail'] ?? (string) $schedule->blocked_reason]);
        }

        return $schedule;
    }

    /**
     * Why "Publish now" cannot work for this article right now, in the
     * owner's words, or null when it can. Shared by the button, which shows
     * the reason beside itself, and the endpoint, which refuses with it.
     */
    public function publishNowRefusal(ContentItem $item, ?ArticleSchedule $schedule): ?string
    {
        $item->loadMissing('project');
        $project = $item->project;
        $delivery = $schedule?->delivery_id === null ? null : $schedule->delivery;

        return match (true) {
            $item->state->isLive(), $schedule?->status === 'completed' => 'This article is already live on your website.',
            ! in_array($item->state, [ContentItemState::Draft, ContentItemState::Approved], true) => 'This article is still being written. You can publish it once it is ready.',
            $schedule?->status === 'dispatching' && $delivery?->status !== DeliveryStatus::DeadLetter => 'This article is already on its way to your website.',
            $delivery !== null && ($delivery->attempts > 0 || $delivery->article_attempt_started_at !== null) => "The last attempt didn't go through. Use Try again on the article instead.",
            $this->channels($project)->isEmpty() => 'Connect your website first.',
            $this->target($project, $schedule) === null => 'Choose which website this article should go to first.',
            $project->status !== ProjectStatus::Active => 'Content work is paused for this business. Resume it to publish.',
            ! $this->entitlements->for($project)->mayPublish() => "Your plan doesn't include publishing yet.",
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function props(ContentItem $item): array
    {
        $item->loadMissing(['articleSchedule.delivery', 'project.channels']);
        $schedule = $item->articleSchedule;
        $delivery = $schedule?->delivery_id === null ? null : $schedule->delivery;
        $status = match (true) {
            $item->state->isLive() => 'published',
            $schedule?->status === 'completed' => 'published',
            $schedule?->status === 'dispatching' && $delivery?->status === DeliveryStatus::Delivered => 'published',
            $schedule?->status === 'dispatching' && $delivery?->status === DeliveryStatus::DeadLetter => 'blocked',
            $schedule?->status === 'dispatching' => 'publishing',
            in_array($schedule?->status, ['paused', 'canceled'], true) => $schedule->status,
            $schedule?->status === 'blocked' => 'blocked',
            $item->state === ContentItemState::Idea => 'planned',
            in_array($item->state, [ContentItemState::Queued, ContentItemState::Generating], true) => 'writing',
            $item->state === ContentItemState::Draft && ($schedule === null || $schedule->mode === 'review_first' || $item->project->is_ymyl || ($item->factcheck['passed'] ?? false) !== true) => 'needs_review',
            $schedule !== null => 'scheduled',
            default => 'unscheduled',
        };
        $refusal = $this->publishNowRefusal($item, $schedule);

        return [
            'status' => $status, 'timezone' => $item->project->timezone,
            // What every screen shows: one label, one sentence, one next step.
            // See {@see PublicationStatus}.
            'presentation' => PublicationStatus::for($item, $schedule, $delivery),
            // A request may be on its way to the website right now; the
            // schedule cannot be changed under it.
            'in_flight' => $schedule?->status === 'dispatching' && $delivery !== null && ! $delivery->status->isSettled(),
            'publish_now' => [
                'available' => $refusal === null,
                // Only the refusals worth showing beside a button: the others
                // are already the whole story in `presentation`.
                'reason' => in_array($refusal, [null, 'This article is already live on your website.', 'This article is already on its way to your website.', "The last attempt didn't go through. Use Try again on the article instead."], true) ? null : $refusal,
            ],
            'default_mode' => self::modeFor($item->project, held: false),
            'schedule' => $schedule === null ? null : [
                'id' => $schedule->id, 'version' => $schedule->version, 'mode' => $schedule->mode, 'held' => $schedule->held_for_review, 'status' => $schedule->status, 'delivery_id' => $schedule->delivery_id,
                'publish_at' => $schedule->publish_at->toIso8601String(), 'local_date' => $schedule->publish_at->setTimezone($item->project->timezone)->toDateString(),
                'local_time' => $schedule->publish_at->setTimezone($item->project->timezone)->format('H:i'), 'timezone' => $item->project->timezone, 'channel_id' => $schedule->channel_id,
                'blocked_reason' => $schedule->blocked_reason ?? ($status === 'blocked' ? $delivery?->error : null),
            ],
            'channels' => $this->channels($item->project)->map(fn (Channel $channel): array => [
                'id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type->value,
            ])->all(),
            'can_schedule' => ! $item->state->isLive() && $schedule?->status !== 'completed'
                && ($delivery === null || ($delivery->attempts === 0 && $delivery->article_attempt_started_at === null)),
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

    /** The website "Publish now" sends to: the schedule's, if still usable, or the project's only one. */
    private function target(Project $project, ?ArticleSchedule $schedule): ?Channel
    {
        $usable = $this->channels($project);
        $chosen = $schedule?->channel_id === null ? null : $usable->firstWhere('id', $schedule->channel_id);

        return $chosen ?? ($usable->count() === 1 ? $usable->first() : null);
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
