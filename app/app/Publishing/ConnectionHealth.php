<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\Channel;
use App\Models\WebhookDelivery;
use App\Publishing\Articles\ArticleSchedules;

/**
 * Whether a website connection works, in one of five words and a sentence.
 *
 * The answer used to be spread over `verified_at`, a `test_pending` flag and
 * a delivery log the owner never opened: a test that failed showed "Never",
 * and one the receiver kept answering 503 showed "Testing…" for twelve hours.
 * The website page and Home's checklist both ask this class, so they cannot
 * disagree about whether the site is connected.
 *
 * @phpstan-type Health array{
 *     state: 'connected'|'testing'|'failed'|'untested'|'paused',
 *     headline: string,
 *     detail: string|null,
 *     checked_at: string|null,
 * }
 */
final class ConnectionHealth
{
    /**
     * How long a test may sit unsent before it counts as not having run.
     *
     * A test is one attempt that answers within the HTTP timeout. One still
     * pending minutes later is waiting on a queue that is not moving, and
     * "Testing…" would then be a promise the page keeps polling on forever.
     */
    private const int STALLED_AFTER_SECONDS = 300;

    /** @return Health */
    public static function for(Channel $channel): array
    {
        if (! $channel->is_enabled) {
            return self::health('paused', 'Paused', 'Avyo does not send articles to this website while it is paused.');
        }

        $ping = self::latestPing($channel);

        if ($ping !== null && $ping->status === DeliveryStatus::Pending) {
            if ($ping->created_at !== null && $ping->created_at->lt(now()->subSeconds(self::STALLED_AFTER_SECONDS))) {
                return self::health(
                    'failed',
                    "Couldn't connect",
                    "The test didn't run. Try again in a few minutes.",
                    $ping->created_at->toIso8601String(),
                );
            }

            return self::health('testing', 'Testing the connection…', null, $ping->created_at?->toIso8601String());
        }

        // A test answers now, in one attempt, so a ping still on the retry
        // ladder is one that failed before tests stopped retrying. Its
        // attempt did not work; saying "Testing…" until its twelve-hour rung
        // comes round is the complaint this class exists to answer.
        $unanswered = $ping !== null && in_array($ping->status, [DeliveryStatus::DeadLetter, DeliveryStatus::Retrying], true);

        // A failed test is news only if nothing has worked since: a newer
        // pass has already answered the question.
        if ($unanswered && ($channel->verified_at === null || $channel->verified_at->lt($ping->updated_at))) {
            return self::health(
                'failed',
                "Couldn't connect",
                DeliveryExplanation::explain($ping->response_code, $ping->error) ?? 'Your website did not answer the test with success.',
                $ping->updated_at?->toIso8601String(),
            );
        }

        if ($channel->verified_at !== null && app(ArticleSchedules::class)->compatible($channel)) {
            return self::health('connected', 'Connected', null, $channel->verified_at->toIso8601String());
        }

        return self::health('untested', 'Not tested yet', 'Send a test so Avyo can check that your website receives articles.');
    }

    private static function latestPing(Channel $channel): ?WebhookDelivery
    {
        return WebhookDelivery::query()
            ->where('channel_id', $channel->getKey())
            ->whereNull('content_item_id')
            ->where('payload_snapshot->event', WebhookEvent::Ping->value)
            ->latest('created_at')
            ->latest('id')
            ->first();
    }

    /**
     * @param  'connected'|'testing'|'failed'|'untested'|'paused'  $state
     * @return Health
     */
    private static function health(string $state, string $headline, ?string $detail, ?string $checkedAt = null): array
    {
        return ['state' => $state, 'headline' => $headline, 'detail' => $detail, 'checked_at' => $checkedAt];
    }
}
