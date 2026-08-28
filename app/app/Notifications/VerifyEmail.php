<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as FrameworkVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

/**
 * The framework's verification mail, moved off the request thread.
 *
 * Laravel sends this one inline, which was harmless while the mailer was `log`
 * and stops being harmless the moment it is a provider over HTTP: the send
 * happens inside the registration POST, so a slow Resend makes registration
 * slow and a failing Resend makes it a 500. Worse than the 500 is what it
 * leaves behind — {@see RegisteredUserController} fires `Registered` before it
 * logs the new user in, so the account row already exists. The person sees a crash, is not signed in, and cannot register again
 * because their address is taken.
 *
 * Queued, the same failure is a job in Horizon that retries and is visible to
 * whoever is on call, while registration itself completes.
 *
 * `ShouldQueueAfterCommit` rather than `ShouldQueue`: the notifiable is
 * serialised by id and re-read when the job runs, so a worker that picks this
 * up before the enclosing transaction commits would find no user. Nothing
 * wraps account creation in one today. That is not a property worth depending
 * on from here.
 */
final class VerifyEmail extends FrameworkVerifyEmail implements ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Four attempts over about six minutes, then it stops and stays in the
     * failed table. A provider's 500 is usually gone by the second try; past
     * that, the delay itself is the problem — somebody is sitting on a signup
     * screen waiting for this mail — and a job retried for an hour only hides
     * that the address needs the resend button instead.
     *
     * Four and not three because `backoff()` has three delays and the last
     * gap is only waited if there is an attempt left to make after it. At
     * three the 300 is never reached and the real window is seventy seconds,
     * which is not what the paragraph above claims.
     */
    public int $tries = 4;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }
}
