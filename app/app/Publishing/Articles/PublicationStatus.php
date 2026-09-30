<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\ProjectStatus;
use App\Enums\WebhookEvent;
use App\Models\ArticleSchedule;
use App\Models\ContentItem;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\DeliveryExplanation;
use App\Publishing\StrandedDeliveries;
use App\Publishing\WebhookPublisher;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Where an article has got to on its way to the owner's website, in words.
 *
 * The state is spread over three rows — the article, its schedule and the
 * delivery the schedule made — and each screen used to read a different
 * subset. The article panel said "Publishing · Delivery is in progress" for
 * seventy minutes while a dead worker held the delivery, because nothing it
 * read could tell sending from stuck from waiting twelve hours for a retry.
 * Every screen that says where an article is asks this class, so the panel,
 * the calendar, the content list and Home cannot disagree.
 *
 * Tone is what the owner should feel, not a colour: `attention` is "this is
 * waiting for you", `problem` is "something broke". Waiting for an approval
 * is not an error, and it used to be drawn as one.
 *
 * @phpstan-type Action array{label: string, kind: 'approve'|'retry'|'reschedule'|'connect'|'review'|'plan'|'settings'|'view', href: string, method: 'get'|'post', owner_only: bool, external: bool}
 * @phpstan-type Presentation array{key: string, tone: 'neutral'|'progress'|'success'|'attention'|'problem', label: string, detail: string|null, when: string|null, action: Action|null, secondary: Action|null}
 */
final class PublicationStatus
{
    public const string WAITING = 'Waiting for you';

    public const string FAILED = "Couldn't publish";

    public const string DELAYED = DeliveryExplanation::DELAYED;

    public const string GAVE_UP = DeliveryExplanation::GAVE_UP;

    public const string WAITING_FOR_WEBSITE = 'Waiting for your website';

    public const string STILL_WRITING = 'The article is still being prepared.';

    /**
     * @return Presentation
     */
    public static function for(ContentItem $item, ?ArticleSchedule $schedule = null, ?WebhookDelivery $delivery = null, ?CarbonInterface $now = null): array
    {
        $item->loadMissing('project');
        $project = $item->project;
        $schedule ??= $item->relationLoaded('articleSchedule') ? $item->articleSchedule : $item->articleSchedule()->first();
        $delivery ??= $schedule?->delivery_id === null ? null : $schedule->loadMissing('delivery')->delivery;
        $now = $now === null ? Carbon::now() : Carbon::instance($now);
        $tz = $project->timezone;

        if ($item->state->isLive() || $schedule?->status === 'completed' || $delivery?->status === DeliveryStatus::Delivered) {
            return self::published($item, $delivery, $tz, $now);
        }

        if ($schedule?->status === 'paused') {
            return self::make('paused', 'neutral', 'Paused', 'Publishing is paused for this article. Resume it or pick a new date.',
                null, self::action('Pick a new date', 'reschedule', self::panel($item), ownerOnly: true));
        }

        if ($schedule?->status === 'canceled') {
            return self::make('canceled', 'neutral', 'Not scheduled', 'Its date was canceled. Pick a new date to publish it.',
                null, self::action('Pick a new date', 'reschedule', self::panel($item), ownerOnly: true));
        }

        if ($schedule?->status === 'dispatching' && $delivery !== null) {
            return self::delivery($item, $delivery, $tz, $now);
        }

        if ($schedule?->status === 'dispatching') {
            return self::make('sending', 'progress', 'Sending', 'Sending to your website…');
        }

        // Avyo tried, then handed it back for the owner's yes: approving
        // sends it again. Not a failure, so no "Try again".
        if ($item->state === ContentItemState::Draft && ArticleSchedules::awaitingOwnerAfterAttempt($schedule)) {
            assert($schedule instanceof ArticleSchedule);

            return self::make('waiting', 'attention', self::WAITING, $schedule->blocked_code === BlockedCode::FACT_CHECK
                ? 'The fact check found something to look at. Review it, then approve to send it again.'
                : 'Approve it to send it again.', null, self::approve($item));
        }

        if ($schedule?->status === 'blocked') {
            if ($schedule->blocked_reason === null && $delivery?->status === DeliveryStatus::DeadLetter) {
                return self::delivery($item, $delivery, $tz, $now);
            }

            return self::blocked($item, $project, $schedule, $delivery, $tz, $now);
        }

        if ($item->state === ContentItemState::Idea) {
            return self::make('planned', 'neutral', 'Planned', $schedule === null
                ? 'Avyo will write it closer to its date.'
                : 'Avyo will write it before it publishes '.self::moment($schedule->publish_at, $tz, $now).'.',
                $schedule?->publish_at->toIso8601String());
        }

        if (in_array($item->state, [ContentItemState::Queued, ContentItemState::Generating], true)) {
            return self::make('writing', 'progress', 'Writing', 'Being written.');
        }

        if ($item->state === ContentItemState::Draft) {
            return self::draft($item, $project, $schedule, $tz, $now);
        }

        // Approved.
        if ($schedule === null) {
            return self::make('ready', 'neutral', 'Ready to publish', 'Pick a date, or publish it now.',
                null, self::action('Pick a date', 'reschedule', self::panel($item), ownerOnly: true));
        }

        return self::scheduled($schedule, $tz, $now);
    }

    /**
     * What the owner should do about a refusal sentence, wherever it came from.
     *
     * The sentences are written by the schedule, the approval and the delivery
     * guard, each for its own reasons. Sorting them here, once, is what lets
     * a fact-check hold say "Review article" instead of pointing at the
     * website settings — which is where every one of them used to link.
     *
     * Only for rows written before `blocked_code` existed, and for delivery
     * errors, which carry no code: see {@see kindForCode()}.
     *
     * @return 'website'|'choose_website'|'missed'|'paused'|'plan'|'allowance'|'approve'|'review'|'writing'|'reschedule'|'retry'|'other'
     */
    public static function reasonKind(?string $reason, ?Project $project = null): string
    {
        $text = strtolower((string) $reason);

        return match (true) {
            $text === '' => 'other',
            str_contains($text, 'choose which website') => 'choose_website',
            str_contains($text, 'missed') => 'missed',
            str_contains($text, 'paused for this project'), str_contains($text, 'automatic publication is paused') => $project !== null && $project->status !== ProjectStatus::Active ? 'paused' : 'plan',
            str_contains($text, 'no articles remain') => 'allowance',
            str_contains($text, 'active plan'), str_contains($text, 'publication grace') => 'plan',
            str_contains($text, 'website'), str_contains($text, 'connection'), str_contains($text, 'endpoint') => 'website',
            str_contains($text, 'review and approve'), str_contains($text, 'no active automatic schedule'),
            str_contains($text, 'approve the article first') => 'approve',
            str_contains($text, 'fact check'), str_contains($text, 'needs a person'), str_contains($text, 'needs attention:'),
            str_contains($text, 'business information'), str_contains($text, 'article changed'), str_contains($text, 'review the') => 'review',
            str_contains($text, 'still being prepared') => 'writing',
            str_contains($text, 'turned off'), str_contains($text, 'no longer matches'), str_contains($text, 'schedule it again') => 'reschedule',
            str_contains($text, 'previous delivery') => 'retry',
            default => 'other',
        };
    }

    /**
     * What to show for a stored {@see BlockedCode}. The code is the answer;
     * the sentence beside it is only read for rows that have no code.
     *
     * @return 'website'|'choose_website'|'missed'|'paused'|'plan'|'allowance'|'approve'|'fact_check'|'review'|'website_paused'|'website_not_working'|'retry'|'other'
     */
    public static function kindForCode(string $code): string
    {
        return match ($code) {
            BlockedCode::NO_WEBSITE => 'website',
            BlockedCode::CHOOSE_WEBSITE => 'choose_website',
            BlockedCode::MISSED_DATE => 'missed',
            BlockedCode::PLAN => 'plan',
            BlockedCode::ALLOWANCE_USED => 'allowance',
            BlockedCode::PROJECT_PAUSED => 'paused',
            BlockedCode::NEEDS_APPROVAL => 'approve',
            BlockedCode::FACT_CHECK => 'fact_check',
            BlockedCode::SCORE, BlockedCode::BUSINESS_FACTS => 'review',
            BlockedCode::WEBSITE_PAUSED => 'website_paused',
            BlockedCode::WEBSITE_NOT_WORKING => 'website_not_working',
            BlockedCode::PREVIOUS_DELIVERY => 'retry',
            default => 'other',
        };
    }

    /**
     * Whether a delivery's error is the stranded-delivery sweeper's note
     * about its own workers, matched against the sweeper's own constants.
     * That is written for whoever runs the queue; the owner is told it is
     * late ({@see DELAYED}) or that Avyo gave up ({@see GAVE_UP}).
     */
    public static function isSweeperNote(?string $error): bool
    {
        return StrandedDeliveries::isRequeueNote($error) || StrandedDeliveries::isAbandonedNote($error);
    }

    /**
     * A failure in the owner's words, with the website's type so a WordPress
     * login refusal is not explained as a webhook secret. The channel is
     * loaded explicitly: lazy loading is refused outside production.
     */
    public static function explanation(WebhookDelivery $delivery): ?string
    {
        $delivery->loadMissing('channel');

        return DeliveryExplanation::for($delivery);
    }

    /**
     * Whether "Try again" can do anything: a failed article that was not
     * taken back. A replay of one sent back for changes is only refused.
     */
    public static function canTryAgain(WebhookDelivery $delivery, ?ArticleSchedule $schedule = null): bool
    {
        if ($delivery->status !== DeliveryStatus::DeadLetter || DeliveryExplanation::isWithdrawn($delivery->error)
            || $delivery->contentItem?->state->isLive()) {
            return false;
        }
        $schedule ??= $delivery->article_schedule_id === null ? null : ArticleSchedule::query()->find($delivery->article_schedule_id);
        if ($schedule === null) {
            return true;
        }

        // Only the attempt the schedule still follows: one superseded by a
        // fresh delivery, or taken off it, would be refused by the replay.
        // Nor one handed back to the owner: approving sends it again.
        return $schedule->delivery_id === $delivery->getKey()
            && ! ArticleSchedules::awaitingOwnerAfterAttempt($schedule);
    }

    /** Held while the website's connection fails its test; it goes when a test passes. */
    public static function isWaitingForWebsite(WebhookDelivery $delivery): bool
    {
        return $delivery->status === DeliveryStatus::Retrying
            && ($delivery->error === WebhookPublisher::WAITING_FOR_WEBSITE || self::isWaitingForResume($delivery));
    }

    /** Held because the owner switched the website off; resuming it sends it. */
    public static function isWaitingForResume(WebhookDelivery $delivery): bool
    {
        return $delivery->status === DeliveryStatus::Retrying && $delivery->error === WebhookPublisher::WAITING_FOR_RESUME;
    }

    /**
     * Whether a failed delivery is one the owner fixes on their website rather
     * than by trying again: a wrong secret, a wrong address, a certificate.
     */
    public static function isConnectionProblem(WebhookDelivery $delivery): bool
    {
        $code = $delivery->response_code;
        if ($code !== null) {
            return in_array($code, [401, 403, 404, 405, 410], true) || ($code >= 300 && $code < 400);
        }
        $error = strtolower((string) $delivery->error);

        return str_contains($error, 'curl error 6:') || str_contains($error, 'could not resolve')
            || str_contains($error, 'ssl') || str_contains($error, 'certificate')
            || str_contains($error, 'no endpoint') || str_contains($error, 'no webhook address');
    }

    /**
     * One attempt to send, for the history lists: what happened, why, and
     * when Avyo tries again — the same words the article's status uses.
     *
     * @return array{label: string, tone: 'neutral'|'progress'|'success'|'attention'|'problem', explanation: string|null, next_attempt: string|null}
     */
    public static function attempt(WebhookDelivery $delivery, string $timezone, ?CarbonInterface $now = null): array
    {
        $stranded = StrandedDeliveries::includes($delivery, $now === null ? null : Carbon::instance($now))
            || ($delivery->status === DeliveryStatus::Retrying && StrandedDeliveries::isRequeueNote($delivery->error));
        $waiting = self::isWaitingForWebsite($delivery);
        $test = ($delivery->payload_snapshot['event'] ?? null) === WebhookEvent::Ping->value;
        $next = ! $stranded && ! $waiting && $delivery->status === DeliveryStatus::Retrying && $delivery->next_attempt_at !== null
            ? self::moment($delivery->next_attempt_at, $timezone, $now) : null;

        return [
            'label' => match (true) {
                $stranded => 'Delayed',
                $waiting => self::WAITING_FOR_WEBSITE,
                $delivery->status === DeliveryStatus::DeadLetter && DeliveryExplanation::isWithdrawn($delivery->error) => 'Not sent',
                $delivery->status === DeliveryStatus::Delivered => $test ? 'Test passed' : 'Published',
                $delivery->status === DeliveryStatus::Pending => 'Sending',
                $delivery->status === DeliveryStatus::Retrying => 'Retrying',
                default => $test ? 'Test failed' : self::FAILED,
            },
            'tone' => match (true) {
                $delivery->status === DeliveryStatus::Delivered => 'success',
                $delivery->status === DeliveryStatus::DeadLetter => DeliveryExplanation::isWithdrawn($delivery->error) ? 'neutral' : 'problem',
                $stranded, $delivery->status === DeliveryStatus::Retrying => 'attention',
                default => 'progress',
            },
            'explanation' => match (true) {
                $stranded => self::DELAYED,
                $waiting => self::isWaitingForResume($delivery) ? DeliveryExplanation::WAITING_FOR_RESUME
                    : 'Avyo will send it as soon as your website connection passes a test.',
                default => self::explanation($delivery),
            },
            'next_attempt' => $next === null ? null : 'Avyo will try again '.$next.'.',
        ];
    }

    /** "today at 09:00", "tomorrow at 09:00", "Thu 2 Oct at 09:00" — in the project's time zone. */
    public static function moment(CarbonInterface $at, string $timezone, ?CarbonInterface $now = null): string
    {
        $local = Carbon::instance($at)->setTimezone($timezone);
        $today = ($now === null ? Carbon::now($timezone) : Carbon::instance($now)->setTimezone($timezone))->startOfDay();
        $time = $local->format('H:i');
        $day = $local->copy()->startOfDay();

        return match (true) {
            $day->equalTo($today) => "today at {$time}",
            $day->equalTo($today->copy()->addDay()) => "tomorrow at {$time}",
            $day->equalTo($today->copy()->subDay()) => "yesterday at {$time}",
            $local->year === $today->year => $local->format('D j M').' at '.$time,
            default => $local->format('j M Y').' at '.$time,
        };
    }

    /** "2 Oct 2026" — for dates that are about the past, where the time is noise. */
    public static function date(CarbonInterface $at, string $timezone): string
    {
        return Carbon::instance($at)->setTimezone($timezone)->format('j M Y');
    }

    /** @return Presentation */
    private static function published(ContentItem $item, ?WebhookDelivery $delivery, string $tz, Carbon $now): array
    {
        $at = $item->published_at ?? $delivery?->delivered_at;
        $url = $item->public_url;

        return self::make('published', 'success', 'Published',
            $at === null ? 'Live on your website.' : 'Published '.self::date($at, $tz).'.',
            $at?->toIso8601String(),
            $url === null ? null : self::action('View on your site', 'view', $url, external: true));
    }

    /** @return Presentation */
    private static function delivery(ContentItem $item, WebhookDelivery $delivery, string $tz, Carbon $now): array
    {
        if ($delivery->status === DeliveryStatus::Delivered) {
            return self::published($item, $delivery, $tz, $now);
        }

        if ($delivery->status === DeliveryStatus::Pending) {
            return StrandedDeliveries::includes($delivery, $now)
                ? self::make('delayed', 'attention', 'Delayed', self::DELAYED)
                : self::make('sending', 'progress', 'Sending', 'Sending to your website…');
        }

        // The sweeper re-queued it: late, not refused.
        if ($delivery->status === DeliveryStatus::Retrying && StrandedDeliveries::isRequeueNote($delivery->error)) {
            return self::make('delayed', 'attention', 'Delayed', self::DELAYED);
        }

        // Held, not refused: the website failed its test, and a passing test
        // sends it on without spending an attempt.
        if (self::isWaitingForResume($delivery)) {
            return self::make('waiting_website', 'attention', self::WAITING_FOR_WEBSITE, DeliveryExplanation::WAITING_FOR_RESUME,
                null, self::action('Open website connection', 'connect', '/channels', ownerOnly: true));
        }

        if (self::isWaitingForWebsite($delivery)) {
            return self::make('waiting_website', 'attention', self::WAITING_FOR_WEBSITE,
                'Avyo will send it as soon as your website connection passes a test.',
                null, self::action('Check website connection', 'connect', '/channels', ownerOnly: true));
        }

        if ($delivery->status === DeliveryStatus::DeadLetter && $delivery->error === WebhookPublisher::WEBSITE_STAYED_BROKEN) {
            return self::make('failed', 'problem', self::FAILED,
                "Your website's connection stayed broken for a day, so it wasn't sent. Test the connection, then try again.",
                null, self::action('Check website connection', 'connect', '/channels', ownerOnly: true), self::retry($delivery));
        }

        if ($delivery->status === DeliveryStatus::DeadLetter && DeliveryExplanation::isWithdrawn($delivery->error)) {
            return self::make('withdrawn', 'neutral', 'Not sent', self::explanation($delivery));
        }

        $explanation = self::explanation($delivery);

        if ($delivery->status === DeliveryStatus::Retrying) {
            $next = $delivery->next_attempt_at;
            $again = $next === null || $next->lessThanOrEqualTo($now)
                ? 'Avyo will try again shortly.'
                : 'Avyo will try again '.self::moment($next, $tz, $now).'.';

            return self::make('retrying', 'attention', 'Retrying',
                ($explanation ?? "Your website didn't accept it.").' '.$again,
                $next?->toIso8601String(),
                self::isConnectionProblem($delivery) ? self::action('Check website connection', 'connect', '/channels', ownerOnly: true) : null);
        }

        // Dead letter: out of attempts, or refused for a reason retrying
        // cannot fix. Say which, and offer the fix that matches.
        $detail = $explanation ?? "Your website didn't accept it.";
        if (self::isConnectionProblem($delivery)) {
            return self::make('failed', 'problem', self::FAILED, $detail, null,
                self::action('Check website connection', 'connect', '/channels', ownerOnly: true));
        }

        if ($delivery->response_code === null && ! self::isSweeperNote($delivery->error)
            && ! str_contains(strtolower((string) $delivery->error), 'curl')) {
            $kind = self::reasonKind($delivery->error, $item->project);
            if (! in_array($kind, ['other', 'retry'], true)) {
                return self::forReason($item, $item->project, $kind, $delivery->error, null, $tz, $now);
            }
        }

        return self::make('failed', 'problem', self::FAILED, $detail, null, self::retry($delivery));
    }

    /** @return Action */
    private static function retry(WebhookDelivery $delivery): array
    {
        return self::action('Try again', 'retry', '/deliveries/'.$delivery->getKey().'/replay', 'post', ownerOnly: true);
    }

    /** @return Presentation */
    private static function blocked(ContentItem $item, Project $project, ArticleSchedule $schedule, ?WebhookDelivery $delivery, string $tz, Carbon $now): array
    {
        $kind = $schedule->blocked_code === null
            ? self::reasonKind($schedule->blocked_reason, $project)
            : self::kindForCode($schedule->blocked_code);

        if ($kind === 'retry') {
            return self::previousDelivery($item, $delivery);
        }

        return self::forReason($item, $project, $kind, $schedule->blocked_reason, $schedule, $tz, $now);
    }

    /**
     * The article was refused because an earlier attempt failed. Say why
     * that attempt failed and offer to try it again.
     *
     * @return Presentation
     */
    private static function previousDelivery(ContentItem $item, ?WebhookDelivery $delivery): array
    {
        if ($delivery?->status !== DeliveryStatus::DeadLetter) {
            // The schedule does not keep the failed attempt it was refused
            // for, so find it: this state is rare, and the query is one.
            $delivery = WebhookDelivery::query()->with('channel')
                ->where('content_item_id', $item->getKey())->where('status', DeliveryStatus::DeadLetter->value)
                ->latest()->latest('id')->first();
        }

        if ($delivery === null) {
            return self::make('failed', 'problem', self::FAILED, "The last attempt didn't go through.",
                null, self::action('Open publishing history', 'view', '/deliveries'));
        }

        return self::make('failed', 'problem', self::FAILED, self::explanation($delivery) ?? "The last attempt didn't go through.",
            null, self::canTryAgain($delivery) ? self::retry($delivery) : null);
    }

    /** @return Presentation */
    private static function forReason(ContentItem $item, Project $project, string $kind, ?string $reason, ?ArticleSchedule $schedule, string $tz, Carbon $now): array
    {
        $due = $schedule === null ? null : self::moment($schedule->publish_at, $tz, $now);
        $hasWebsite = $kind === 'website' && ($project->relationLoaded('channels') ? $project->channels->isNotEmpty() : $project->channels()->exists());

        return match ($kind) {
            'website' => self::make('failed', 'problem', self::FAILED,
                $hasWebsite ? "Your website connection isn't working yet, so Avyo can't send it." : "Your website isn't connected yet.",
                null, self::action($hasWebsite ? 'Check website connection' : 'Connect your website', 'connect', '/channels', ownerOnly: true)),
            'choose_website' => self::make('waiting', 'attention', self::WAITING, 'Choose which website it should go to.',
                null, self::action('Choose a website', 'reschedule', self::panel($item), ownerOnly: true)),
            'missed' => self::make('waiting', 'attention', self::WAITING, 'Its date passed before it went out. Pick a new date to publish it.',
                null, self::action('Pick a new date', 'reschedule', self::panel($item), ownerOnly: true)),
            'paused' => self::make('paused', 'attention', 'Paused', 'Content work is paused for this business. Resume it to publish.',
                null, self::action('Resume content work', 'settings', '/projects/'.$project->getKey().'/edit', ownerOnly: true)),
            'plan' => self::make('waiting', 'attention', self::WAITING, "Your plan doesn't include publishing yet. Choose a plan to publish it.",
                null, self::action('Choose a plan', 'plan', '/billing', ownerOnly: true)),
            'allowance' => self::make('waiting', 'attention', self::WAITING,
                "You've used this period's articles. It will publish when your allowance renews, or choose a bigger plan.",
                null, self::action('Choose a plan', 'plan', '/billing', ownerOnly: true)),
            'website_not_working' => self::make('failed', 'problem', self::FAILED, "Your website connection isn't working. Test it on the Website page.",
                null, self::action('Check website connection', 'connect', '/channels', ownerOnly: true)),
            'website_paused' => self::make('paused', 'attention', 'Paused', 'Your website connection is paused. Resume it to publish.',
                null, self::action('Open website connection', 'connect', '/channels', ownerOnly: true)),
            'fact_check' => self::make('waiting', 'attention', self::WAITING, 'The fact check found something to look at. Review the article, then approve it.',
                null, self::action('Review article', 'review', '/content/'.$item->getKey())),
            'approve' => self::make('waiting', 'attention', self::WAITING,
                $due === null ? 'Approve it to publish now.' : "It was due {$due}. Approve it to publish now.",
                $schedule?->publish_at->toIso8601String(), self::approve($item)),
            'review' => self::make('waiting', 'attention', self::WAITING, $reason === null || trim($reason) === '' ? 'Review the article, then approve it.' : self::reviewDetail($reason),
                null, self::action('Review article', 'review', '/content/'.$item->getKey())),
            'retry' => self::make('failed', 'problem', self::FAILED, "The last attempt didn't go through.",
                null, self::action('Open publishing history', 'view', '/deliveries')),
            'writing' => self::make('writing', 'progress', 'Writing', self::STILL_WRITING),
            'reschedule' => self::make('waiting', 'attention', self::WAITING, 'Its publishing settings changed before it went out. Pick a new date to publish it.',
                null, self::action('Pick a new date', 'reschedule', self::panel($item), ownerOnly: true)),
            // `other`: the stored sentence, unless there is none or the
            // article simply is not written yet.
            default => $reason === null || trim($reason) === '' || $reason === ArticleSchedules::STILL_WRITING
                || in_array($item->state, [ContentItemState::Idea, ContentItemState::Queued, ContentItemState::Generating], true)
                ? self::make('writing', 'progress', 'Writing', self::STILL_WRITING)
                : self::make('failed', 'problem', self::FAILED, $reason, null, null),
        };
    }

    /** @return Presentation */
    private static function draft(ContentItem $item, Project $project, ?ArticleSchedule $schedule, string $tz, Carbon $now): array
    {
        if ($schedule === null) {
            return self::make('waiting', 'attention', self::WAITING, 'Read it and approve it. Then pick a date, or publish it now.',
                null, self::approve($item));
        }

        $needsPerson = $schedule->mode === 'review_first' || $project->is_ymyl || ($item->factcheck['passed'] ?? false) !== true;
        if (! $needsPerson) {
            return self::scheduled($schedule, $tz, $now, 'Avyo checks it first.');
        }

        $when = self::moment($schedule->publish_at, $tz, $now);

        return self::make('waiting', 'attention', self::WAITING,
            $schedule->publish_at->isFuture() ? "Approve it to publish {$when}." : "It was due {$when}. Approve it to publish now.",
            $schedule->publish_at->toIso8601String(), self::approve($item));
    }

    /** @return Presentation */
    private static function scheduled(ArticleSchedule $schedule, string $tz, Carbon $now, ?string $suffix = null): array
    {
        $detail = $schedule->publish_at->greaterThan($now)
            ? 'Publishes '.self::moment($schedule->publish_at, $tz, $now).'.'
            : 'Publishing shortly.';

        return self::make('scheduled', 'neutral', 'Scheduled', trim($detail.' '.($suffix ?? '')), $schedule->publish_at->toIso8601String());
    }

    private static function reviewDetail(string $reason): string
    {
        $text = strtolower($reason);

        return match (true) {
            str_contains($text, 'fact check') => 'The fact check found something to look at. Review the article, then approve it.',
            str_contains($text, 'needs a person') => 'This topic needs a person to approve it before it publishes.',
            // The guard's and the transport's sentences in plain words; one
            // nobody has named (a score's list of fixes) is already plain.
            default => ($plain = DeliveryExplanation::explain(null, $reason)) === null || $plain === DeliveryExplanation::GENERIC ? $reason : $plain,
        };
    }

    /** @return Action */
    private static function approve(ContentItem $item): array
    {
        return self::action('Approve', 'approve', '/content/'.$item->getKey().'/approve', 'post');
    }

    private static function panel(ContentItem $item): string
    {
        return '/content/'.$item->getKey().'#publication';
    }

    /**
     * @param  'approve'|'retry'|'reschedule'|'connect'|'review'|'plan'|'settings'|'view'  $kind
     * @param  'get'|'post'  $method
     * @return Action
     */
    private static function action(string $label, string $kind, string $href, string $method = 'get', bool $ownerOnly = false, bool $external = false): array
    {
        return ['label' => $label, 'kind' => $kind, 'href' => $href, 'method' => $method, 'owner_only' => $ownerOnly, 'external' => $external];
    }

    /**
     * @param  'neutral'|'progress'|'success'|'attention'|'problem'  $tone
     * @param  Action|null  $action
     * @param  Action|null  $secondary
     * @return Presentation
     */
    private static function make(string $key, string $tone, string $label, ?string $detail, ?string $when = null, ?array $action = null, ?array $secondary = null): array
    {
        return ['key' => $key, 'tone' => $tone, 'label' => $label, 'detail' => $detail, 'when' => $when, 'action' => $action, 'secondary' => $secondary];
    }
}
