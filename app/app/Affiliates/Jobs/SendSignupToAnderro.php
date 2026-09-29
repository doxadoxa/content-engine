<?php

declare(strict_types=1);

namespace App\Affiliates\Jobs;

use App\Affiliates\Anderro;
use App\Affiliates\Exceptions\AffiliateEventRejected;
use App\Affiliates\Referrals;
use App\Models\AffiliateReferral;
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
 * The referral was already recorded when this was dispatched — see
 * {@see Referrals::signedUp()} for why that is not left until Anderro answers —
 * and what is sent is read from it, not from the account: the address Anderro
 * is told about has to be the one later payments are reported under. Consent is
 * checked again here, because a retry can run ten minutes after somebody
 * switched marketing off.
 */
final class SendSignupToAnderro implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public int $userId) {}

    public function handle(Anderro $anderro): void
    {
        $referral = AffiliateReferral::query()->where('user_id', $this->userId)->first();

        if ($referral === null || ! $referral->mayReport() || ! $anderro->isConfigured()) {
            return;
        }

        try {
            $anderro->signup($referral->email, $referral->visitor_id);
        } catch (AffiliateEventRejected $e) {
            if ($e->isPermanent()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
