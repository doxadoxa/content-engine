<?php

declare(strict_types=1);

namespace App\Affiliates\Jobs;

use App\Affiliates\Anderro;
use App\Affiliates\Exceptions\AffiliateEventRejected;
use App\Models\AffiliateReferral;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Tell Anderro that a referred customer paid, so the partner's commission can
 * be worked out.
 *
 * Retried on the same terms as {@see SendSignupToAnderro}, with one difference
 * worth knowing about: payments are not deduplicated on Anderro's side, and the
 * API takes no idempotency key. A request that reached Anderro and then timed
 * out on the way back would be sent again and counted twice. That is the rarer
 * failure by a distance — a refused connection or a 5xx, which are the common
 * ones, never reached it — and a commission counted twice is visible in their
 * dashboard and correctable, where one never sent is invisible. So it retries.
 *
 * Sent under the address the sign-up was reported with, not the account's
 * current one: Anderro attributes payments by address, and a customer who
 * changed theirs here would otherwise stop earning their partner anything.
 * And consent is checked again when it runs, not only when it was queued — a
 * delayed or retried report must not go out after somebody has said no.
 */
final class SendPaymentToAnderro implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public function __construct(public int $userId, public int $amountCents) {}

    public function handle(Anderro $anderro): void
    {
        $referral = AffiliateReferral::query()->where('user_id', $this->userId)->first();

        if ($referral === null || ! $referral->mayReport() || ! $anderro->isConfigured()) {
            return;
        }

        try {
            $anderro->payment($referral->email, $this->amountCents);
        } catch (AffiliateEventRejected $e) {
            if ($e->isPermanent()) {
                $this->fail($e);

                return;
            }

            throw $e;
        }
    }
}
