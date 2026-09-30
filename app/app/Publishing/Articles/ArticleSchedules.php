<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Billing\Entitlements;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\ProjectStatus;
use App\Enums\WebhookEvent;
use App\Models\ArticleSchedule;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Publishing\ChannelPublisherRegistry;
use App\Publishing\HeldArticles;
use App\Publishing\Jobs\DeliverWebhookJob;
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

    public const string ON_ITS_WAY = "It's already on its way.";

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
                'blocked_code' => $target === null ? ($targets->isEmpty() ? BlockedCode::NO_WEBSITE : BlockedCode::CHOOSE_WEBSITE) : null,
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
            $newlyHeld = $held && ! ($schedule->held_for_review ?? false) && $project->autopublish;
            $schedule ??= new ArticleSchedule(['content_item_id' => $item->id, 'version' => 0]);
            $keep = $this->withdrawQueued($schedule);
            $mode = self::modeFor($project, $held);
            // A schedule that waits for review takes back Avyo's own approval,
            // always. A person's only when the owner has just ticked "Hold"
            // on an automatic project: that is asking to read it again. On a
            // review-first project a person's approval is exactly what the
            // schedule is waiting for, and picking its date must keep it.
            // Nothing has been sent (withdrawQueued() just made sure), and the
            // allowance record stays, so approving it again is not charged.
            if ($mode === 'review_first' && ($schedule->approved_by_avyo || $newlyHeld)) {
                $this->returnToReview($schedule, $item, whoever: true);
            }
            $schedule->fill([
                'channel_id' => $channel->id, 'publish_at' => $at, 'local_date' => $input['local_date'],
                'local_time' => $input['local_time'], 'timezone' => $project->timezone,
                'mode' => $mode, 'held_for_review' => $held, 'status' => 'active',
                'origin' => 'manager', 'requested_by' => $actor->id, 'version' => $schedule->version + 1,
                'blocked_reason' => null, 'blocked_code' => null, 'delivery_id' => $keep ? $schedule->delivery_id : null,
            ])->save();

            return $schedule;
        });
    }

    /**
     * Pause, resume or cancel one article's schedule.
     *
     * `$sendingBack` is the Send back button, which pauses the schedule while
     * the article is reworked. On an article handed back after an attempt
     * ({@see awaitOwner()}) that pause has nothing to add — it already waits
     * for the owner, and paused it would wait on a resume its past date
     * refuses — so it is left as it is. Anything else asked of such an
     * article is refused: approving it or sending it back are the two ways
     * forward, and a cancelled or resumed schedule holding an attempt that may
     * have arrived had none.
     */
    public function change(ContentItem $item, int $expectedVersion, string $action, bool $sendingBack = false): ArticleSchedule
    {
        return $this->mutate($item, function (?ArticleSchedule $schedule) use ($item, $expectedVersion, $action, $sendingBack): ArticleSchedule {
            $this->version($schedule, $expectedVersion);
            abort_if($schedule === null, 404);
            $this->require(in_array($action, ['pause', 'resume', 'cancel'], true), 'Unknown scheduling action.');
            $this->require($schedule->status !== 'completed', 'This article was already published.');
            if (self::awaitingOwnerAfterAttempt($schedule) && $this->mayHaveArrived($schedule)) {
                if ($sendingBack && $action === 'pause') {
                    return $schedule;
                }
                $this->require(false, 'Approve it or send it back instead.');
            }
            if ($action === 'resume') {
                $this->require($schedule->publish_at->isFuture(), 'The original time has passed. Choose a new publication time.');
            }
            $keep = $this->withdrawQueued($schedule);
            // Sent back while its attempt waited on the website: that attempt
            // may have arrived, so the article waits for the owner with it
            // still linked, exactly as a hand-back does, and their approval
            // settles it under the same id.
            if ($keep && $sendingBack && $action === 'pause') {
                $item->loadMissing('project');
                $schedule->forceFill([
                    'status' => 'blocked', 'blocked_code' => BlockedCode::NEEDS_APPROVAL, 'blocked_reason' => self::NEEDS_APPROVAL,
                    'approved_by_avyo' => false, 'mode' => self::modeFor($item->project, $schedule->held_for_review),
                    'version' => $schedule->version + 1,
                ])->save();

                return $schedule;
            }
            $schedule->forceFill([
                'status' => match ($action) {
                    'pause' => 'paused', 'cancel' => 'canceled', default => 'active'
                },
                'version' => $schedule->version + 1, 'blocked_reason' => null, 'blocked_code' => null,
                'delivery_id' => $keep ? $schedule->delivery_id : null,
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
     * owner asked for that article to wait whatever the project does.
     *
     * Going review-first also takes back what Avyo approved on its own: the
     * engine approves a few days ahead, and "Let me review first" has to mean
     * those too, not only the drafts it had not reached yet. They go back to
     * waiting for the owner; their allowance was already counted and is not
     * counted again when the owner approves them.
     *
     * Blocks are lifted with the change, since every reason the mode decides
     * is now out of date and one that is still true is written again by
     * {@see dispatch()}. Two stay: a schedule with no website, which is still
     * the whole story, and one whose date has passed. Lifting that would send
     * a backlog of stale articles the minute the switch moved, so it waits for
     * a new date instead.
     */
    public function followProject(Project $project): void
    {
        $mode = self::modeFor($project, held: false);
        $drifted = fn () => ArticleSchedule::acrossProjects()->where('project_id', $project->getKey())
            ->where('held_for_review', false)->where('mode', '!=', $mode)
            ->whereNull('delivery_id')->whereNotIn('status', ['completed', 'dispatching']);

        if ($mode === 'review_first') {
            foreach ($drifted()->where('approved_by_avyo', true)->get() as $schedule) {
                $this->returnToReview($schedule, ContentItem::acrossProjects()->findOrFail($schedule->content_item_id));
                $schedule->save();
            }
        }

        $lift = fn () => $drifted()->where('status', 'blocked')->whereNotNull('channel_id')
            ->where(fn ($code) => $code->whereNull('blocked_code')->orWhere('blocked_code', '!=', BlockedCode::MISSED_DATE));
        $lift()->where('publish_at', '<=', now())
            ->update(['blocked_reason' => self::MISSED_DATE, 'blocked_code' => BlockedCode::MISSED_DATE]);
        $lift()->update(['status' => 'active', 'blocked_reason' => null, 'blocked_code' => null]);
        $drifted()->update(['mode' => $mode, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
    }

    /**
     * Give engine schedules with nowhere to go the project's one usable website, once there is one.
     *
     * Only the ones still to come become active. A new customer's calendar
     * fills while they are still connecting their website, and releasing
     * every date that passed meanwhile would publish a backlog the minute the
     * test succeeds. Those wait for a new date, as in {@see followProject()};
     * "Publish now" is how the owner sends one straight away.
     */
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
            $waiting = fn () => ArticleSchedule::acrossProjects()->where('project_id', $fresh->getKey())->where('origin', 'engine')
                ->whereNull('channel_id')->whereIn('status', ['active', 'blocked'])->whereNull('delivery_id');
            $attach = ['channel_id' => $target->id, 'version' => DB::raw('version + 1'), 'updated_at' => now()];

            $waiting()->where('publish_at', '<=', now())
                ->update([...$attach, 'status' => 'blocked', 'blocked_reason' => self::MISSED_DATE, 'blocked_code' => BlockedCode::MISSED_DATE]);
            $waiting()->update([...$attach, 'status' => 'active', 'blocked_reason' => null, 'blocked_code' => null]);
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
            // A missed date is answered with a new date, not by the next tick:
            // the article would otherwise go out whenever the block happened
            // to clear, days after anybody expected it.
            if ($schedule->status === 'blocked' && $schedule->blocked_code === BlockedCode::MISSED_DATE) {
                return [];
            }
            $item->refresh()->loadMissing('project');
            // Handed back to the owner after an attempt: nothing changes until
            // a person approves it, and the code keeps saying why it came back.
            if (self::awaitingOwnerAfterAttempt($schedule) && $item->state === ContentItemState::Draft) {
                return [];
            }
            $this->entitlements->forget($project);
            [$reason, $code] = [null, null];
            if ($this->missedAutomaticDate($schedule, $project)) {
                [$reason, $code] = [self::MISSED_DATE, BlockedCode::MISSED_DATE];
            } elseif ($project->status !== ProjectStatus::Active) {
                [$reason, $code] = [self::PAUSED, BlockedCode::PROJECT_PAUSED];
            } elseif (! $this->entitlements->for($project)->mayPublish()) {
                [$reason, $code] = [self::PAUSED, BlockedCode::PLAN];
            }
            $channel = $schedule->channel_id === null ? null : Channel::query()->find($schedule->channel_id);
            if ($reason === null && $channel === null) {
                [$reason, $code] = $this->channels($project)->isEmpty()
                    ? [self::NO_WEBSITE, BlockedCode::NO_WEBSITE] : [self::CHOOSE_WEBSITE, BlockedCode::CHOOSE_WEBSITE];
            } elseif ($reason === null && ! $this->compatible($channel)) {
                [$reason, $code] = [self::WEBSITE_UNUSABLE, self::websiteCode($channel)];
            }
            // Avyo's approval only stands where nobody has to look. A schedule
            // that reviews first (held, or the project reviews everything)
            // waits for a person even if Avyo got to the draft earlier.
            if ($schedule->mode === 'review_first' && $schedule->approved_by_avyo) {
                $this->returnToReview($schedule, $item);
                $schedule->save();
            }
            // Avyo approves only for an automatic schedule on a project that
            // still publishes automatically; everything else waits for a person.
            if ($reason === null && $item->state === ContentItemState::Draft && $schedule->mode === 'automatic' && $project->autopublish) {
                try {
                    app(ArticleApproval::class)->approve($item, automatic: true);
                    $schedule->refresh();
                } catch (ValidationException $exception) {
                    [$reason, $code] = [$exception->getMessage(), $exception instanceof ArticleRefusal ? $exception->blockedCode : BlockedCode::OTHER];
                }
            }
            if ($reason === null && $item->state !== ContentItemState::Approved) {
                [$reason, $code] = $item->state === ContentItemState::Draft
                    ? [self::NEEDS_APPROVAL, BlockedCode::NEEDS_APPROVAL] : [self::STILL_WRITING, BlockedCode::OTHER];
            }
            if ($reason !== null) {
                $schedule->forceFill(['status' => 'blocked', 'blocked_reason' => $reason, 'blocked_code' => $code])->save();

                return [];
            }
            assert($channel instanceof Channel);
            $event = null;
            if ($schedule->delivery_id !== null) {
                [$resent, $event] = $this->resendAfterApproval($schedule, $item);
                if ($resent !== null) {
                    return $resent;
                }
            }
            $delivery = app(PublishToChannels::class)->publishToSelected($item, $channel, $event);
            if ($delivery === null || $delivery->status === DeliveryStatus::DeadLetter) {
                $schedule->forceFill(['status' => 'blocked', 'blocked_reason' => self::PREVIOUS_FAILED, 'blocked_code' => BlockedCode::PREVIOUS_DELIVERY])->save();

                return [];
            }
            $delivery->forceFill(['article_schedule_id' => $schedule->id, 'article_schedule_version' => $schedule->version])->save();
            $schedule->forceFill([
                'status' => $delivery->status === DeliveryStatus::Delivered ? 'completed' : 'dispatching',
                'delivery_id' => $delivery->id, 'blocked_reason' => null, 'blocked_code' => null,
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

    /**
     * Why an unusable website stops an article, as a code: switched off by
     * the owner, or not working (never tested, failed its last test, or
     * missing its secret or address). The two ask for different things.
     */
    public static function websiteCode(Channel $channel): string
    {
        return $channel->is_enabled ? BlockedCode::WEBSITE_NOT_WORKING : BlockedCode::WEBSITE_PAUSED;
    }

    /**
     * Hand an article whose delivery was refused back to the owner.
     *
     * Called by {@see ArticleDeliveryGuard} when the refusal is about approval
     * — Avyo approved it and the owner has since asked to review, or its fact
     * check failed after Avyo approved it. Without this the article stayed
     * approved beside a dead delivery, and every way out was refused: the
     * schedule could not be changed after an attempt, Try again met the same
     * refusal, and there was no Approve button on an approved article.
     *
     * Now it is a draft again, blocked with a code that says why, and still
     * linked to its delivery. A person's approval sends it through
     * {@see dispatch()}, which resends that same delivery if it may already
     * have reached the website. The version is left alone for that reason:
     * the delivery is only sendable while it matches.
     */
    public function awaitOwner(ArticleSchedule $schedule, ContentItem $item, string $code, string $reason): string
    {
        if ($item->state === ContentItemState::Approved) {
            $item->returnForRework();
        }
        $item->loadMissing('project');
        $schedule->forceFill([
            'approved_by_avyo' => false, 'mode' => self::modeFor($item->project, $schedule->held_for_review),
            'status' => 'blocked', 'blocked_code' => $code, 'blocked_reason' => $reason,
        ])->save();

        return $reason;
    }

    /** Waiting for the owner's approval after a delivery attempt ({@see awaitOwner()}). */
    public static function awaitingOwnerAfterAttempt(?ArticleSchedule $schedule): bool
    {
        return $schedule !== null && $schedule->status === 'blocked' && $schedule->delivery_id !== null
            && in_array($schedule->blocked_code, [BlockedCode::NEEDS_APPROVAL, BlockedCode::FACT_CHECK], true);
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
     * are for the Approve button — and an article Avyo had approved becomes
     * one a person approved. The schedule is then moved to this minute and
     * handed to {@see dispatch()}, which is the one path that sends an
     * article: its row locks and its `dispatching` status are what keep the
     * scheduler from sending the same article a second time afterwards.
     *
     * All of it is one transaction, taken behind the project's row lock
     * first. A press that ends up refused leaves nothing behind — no spent
     * allowance, no schedule moved to "now", no hold released — and a second
     * press made while the first is still working waits for it, then hears
     * that the article is already on its way.
     *
     * Refusals come back as a validation error on `publish`, in words the
     * owner can act on.
     */
    public function publishNow(User $actor, ContentItem $item): ArticleSchedule
    {
        abort_unless($actor->projects()->whereKey($item->project_id)->wherePivot('role', 'owner')->exists(), 403);

        return DB::transaction(function () use ($actor, $item): ArticleSchedule {
            $project = Project::query()->whereKey($item->project_id)->lockForUpdate()->firstOrFail();
            $item->refresh()->loadMissing('project');
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

            // Handed back after an attempt: the approval above is what it was
            // waiting for, and dispatch() resends that same delivery. Moving
            // the schedule would give it a new identity it must not have.
            if (! self::awaitingOwnerAfterAttempt($schedule)) {
                $this->mutate($item, function (?ArticleSchedule $schedule) use ($actor, $item, $project): ArticleSchedule {
                    $channel = $this->target($project, $schedule);
                    if ($channel === null) {
                        throw ValidationException::withMessages(['publish' => 'Choose which website this article should go to first.']);
                    }
                    $at = CarbonImmutable::now()->setTimezone($project->timezone);
                    $schedule ??= new ArticleSchedule(['content_item_id' => $item->id, 'version' => 0]);
                    $keep = $this->withdrawQueued($schedule);
                    $schedule->fill([
                        'channel_id' => $channel->id, 'publish_at' => $at->utc(), 'local_date' => $at->toDateString(),
                        'local_time' => $at->format('H:i'), 'timezone' => $project->timezone,
                        'mode' => self::modeFor($project, held: false), 'held_for_review' => false, 'approved_by_avyo' => false, 'status' => 'active',
                        'origin' => 'manager', 'requested_by' => $actor->id, 'version' => $schedule->version + 1,
                        'blocked_reason' => null, 'blocked_code' => null, 'delivery_id' => $keep ? $schedule->delivery_id : null,
                    ])->save();

                    return $schedule;
                });
            }

            $this->dispatch($item);
            $schedule = ArticleSchedule::query()->where('content_item_id', $item->id)->firstOrFail();
            if ($schedule->status === 'blocked') {
                $item->unsetRelation('articleSchedule');
                $presentation = PublicationStatus::for($item->refresh(), $schedule->load('delivery'));

                // Thrown inside the transaction, so the approval and the moved
                // schedule above are rolled back with it.
                throw ValidationException::withMessages(['publish' => $presentation['detail'] ?? (string) $schedule->blocked_reason]);
            }

            return $schedule;
        });
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
            $schedule?->status === 'dispatching' && $delivery?->status !== DeliveryStatus::DeadLetter => self::ON_ITS_WAY,
            $delivery !== null && ($delivery->attempts > 0 || $delivery->article_attempt_started_at !== null)
                && ! self::awaitingOwnerAfterAttempt($schedule) => "The last attempt didn't go through. Use Try again on the article instead.",
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
        $item->loadMissing(['articleSchedule.delivery.channel', 'project.channels']);
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
                'reason' => in_array($refusal, [null, 'This article is already live on your website.', self::ON_ITS_WAY, "The last attempt didn't go through. Use Try again on the article instead."], true) ? null : $refusal,
            ],
            'default_mode' => self::modeFor($item->project, held: false),
            'schedule' => $schedule === null ? null : [
                'id' => $schedule->id, 'version' => $schedule->version, 'mode' => $schedule->mode, 'held' => $schedule->held_for_review, 'status' => $schedule->status, 'delivery_id' => $schedule->delivery_id,
                'publish_at' => $schedule->publish_at->toIso8601String(), 'local_date' => $schedule->publish_at->setTimezone($item->project->timezone)->toDateString(),
                'local_time' => $schedule->publish_at->setTimezone($item->project->timezone)->format('H:i'), 'timezone' => $item->project->timezone, 'channel_id' => $schedule->channel_id,
                'blocked_reason' => $schedule->blocked_reason ?? ($status === 'blocked' ? $delivery?->error : null),
                'blocked_code' => $schedule->blocked_code,
            ],
            'channels' => $this->channels($item->project)->map(fn (Channel $channel): array => [
                'id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type->value,
            ])->all(),
            'can_schedule' => ! $item->state->isLive() && $schedule?->status !== 'completed'
                && ($delivery === null || $delivery->status === DeliveryStatus::DeadLetter
                    || ($delivery->attempts === 0 && $delivery->article_attempt_started_at === null)),
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
     * Take back an approval, for a schedule that now waits for a person.
     *
     * Avyo's own approval only, unless `$whoever`: a hold, or a save on a
     * review-first project, asks to read the article whoever approved it.
     * Only while nothing has been sent; the allowance record stays, so the
     * owner's approval does not count the article again. The caller saves
     * the schedule.
     */
    private function returnToReview(ArticleSchedule $schedule, ContentItem $item, bool $whoever = false): void
    {
        if (! $whoever && (! $schedule->approved_by_avyo || $schedule->delivery_id !== null)) {
            return;
        }
        if ($item->state === ContentItemState::Approved) {
            $item->returnForRework();
        }
        $schedule->approved_by_avyo = false;
    }

    /**
     * Send again the delivery a schedule was waiting on, now that a person
     * has approved the article ({@see awaitOwner()}).
     *
     * An attempt that may have reached the website goes again under the same
     * delivery id, so the receiver can recognise a repeat — as long as it
     * still carries the article as it is. If the article was edited while it
     * waited, resending the old words would be refused, so a fresh delivery
     * goes instead, as an update: the receiver may already hold the earlier
     * version, and replaces it by the article's id. A delivery that never left
     * gives up its identity, and a fresh one follows in the ordinary way.
     *
     * Returns what was resent, or null and the event a fresh delivery should
     * carry (null for the transport's own choice).
     *
     * @return array{list<WebhookDelivery>|null, WebhookEvent|null}
     */
    private function resendAfterApproval(ArticleSchedule $schedule, ContentItem $item): array
    {
        $previous = WebhookDelivery::query()->find($schedule->delivery_id);
        if ($previous === null || $previous->status !== DeliveryStatus::DeadLetter) {
            return [null, null];
        }
        $mayHaveArrived = $previous->attempts > 0 || $previous->article_attempt_started_at !== null;
        if (! $mayHaveArrived || ! app(ArticleDeliveryGuard::class)->snapshotMatches($previous, $item)) {
            // Its identity is spent either way: a later article with the same
            // words must make a new row, not find this dead one.
            $previous->forceFill(['dispatch_key' => null])->save();
            $schedule->delivery_id = null;

            return [null, $mayHaveArrived ? WebhookEvent::Updated : null];
        }
        $lock = Cache::lock('webhook-delivery:'.$previous->id, WebhookPublisher::lockSeconds());
        if (! $lock->get()) {
            return [[], null];
        }
        try {
            // Relinked at the schedule's current version: pausing or sending
            // the article back moved it on, and this resend is the owner's.
            $previous->forceFill(['status' => DeliveryStatus::Pending, 'next_attempt_at' => now(), 'error' => null,
                'deferrals' => 0, 'sweeps' => 0, 'article_schedule_version' => $schedule->version])->save();
            $schedule->forceFill(['status' => 'dispatching', 'blocked_reason' => null, 'blocked_code' => null])->save();
        } finally {
            $lock->release();
        }
        DeliverWebhookJob::dispatch($previous->getKey())->afterCommit();

        return [[$previous], null];
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

    /**
     * Take the schedule's delivery off the queue before the schedule changes,
     * and say whether the schedule must stay linked to it.
     *
     * - Never attempted: dead-lettered and its identity released, unlinked.
     * - Already a dead letter: nothing is in flight. Linked still if it was
     *   attempted, so the next send reconciles with it (see resendAfterApproval()).
     * - Waiting on a paused or broken website ({@see HeldArticles}): nothing is
     *   in flight either, but it may have been attempted before the wait. It is
     *   dead-lettered under its lock and stays linked, keeping its identity.
     * - Anything else attempted is in flight or unsettled, and refused.
     */
    private function withdrawQueued(ArticleSchedule $schedule): bool
    {
        if ($schedule->delivery_id === null) {
            return false;
        }
        $delivery = WebhookDelivery::query()->findOrFail($schedule->delivery_id);
        $this->require($delivery->status !== DeliveryStatus::Delivered, 'This article was already published.');
        if ($delivery->status === DeliveryStatus::DeadLetter) {
            if ($this->mayHaveArrived($schedule)) {
                return true;
            }
            $delivery->forceFill(['dispatch_key' => null])->save();

            return false;
        }
        $attempted = $delivery->attempts > 0 || $delivery->article_attempt_started_at !== null;
        $waiting = $delivery->status === DeliveryStatus::Retrying && in_array($delivery->error, HeldArticles::waitingErrors(), true);
        $this->require(! $attempted || $waiting, 'A delivery has already been attempted. Review its result before creating another publication.');
        $delivery->forceFill(['status' => DeliveryStatus::DeadLetter, 'next_attempt_at' => null,
            'error' => 'The publication schedule changed before this delivery was sent.'])->save();
        if ($attempted) {
            return true;
        }
        // Safe to release the content identity only when no request was attempted.
        $delivery->forceFill(['dispatch_key' => null])->save();

        return false;
    }

    /** Whether the schedule's delivery was attempted, and so may have reached the website. */
    private function mayHaveArrived(ArticleSchedule $schedule): bool
    {
        $delivery = $schedule->delivery_id === null ? null : WebhookDelivery::query()->find($schedule->delivery_id);

        return $delivery !== null && ($delivery->attempts > 0 || $delivery->article_attempt_started_at !== null);
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
