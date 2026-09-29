<?php

declare(strict_types=1);

namespace App\Affiliates\Jobs;

use App\Affiliates\Anderro;
use App\Affiliates\Exceptions\AffiliateEventRejected;
use App\Affiliates\Referrals;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tell Anderro that a referred visitor made an account.
 *
 * Queued, because a sign-up must not wait on — or fail because of — somebody
 * else's API. And, unlike almost every other job here, retried: the jobs that
 * run once do so because a person is watching and can press the button again,
 * and nobody is watching this one. A partner who is not credited for a sign-up
 * finds out at payout time, if at all. Three attempts over ten minutes rides out
 * a bad minute at Anderro; a refusal that will not change on a second try is
 * failed straight away instead — see {@see AffiliateEventRejected}.
 *
 * Retrying cannot credit a sign-up twice: Anderro deduplicates signups by
 * address per day and answers the repeat with `deduplicated: true`.
 *
 * The account was already marked as referred when this was dispatched — see
 * {@see Referrals::signedUp()} for why that is not left until Anderro answers.
 */
final class SendSignupToAnderro implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public int $userId, public string $visitorId) {}

    public function handle(Anderro $anderro): void
    {
        $user = User::query()->whereKey($this->userId)->first();

        if ($user === null || ! $anderro->isConfigured()) {
            return;
        }

        try {
            $anderro->signup($user->email, $this->visitorId);
        } catch (AffiliateEventRejected $e) {
            if ($e->isPermanent()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
