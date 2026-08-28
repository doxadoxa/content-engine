<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as FrameworkResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * The framework's reset mail, moved off the request thread. {@see VerifyEmail}
 * for why, which applies here with one difference: the forgot-password screen
 * answers the same way whether or not the address exists, so an inline failure
 * would turn a deliberately silent route into one that reveals which addresses
 * have accounts by which of them return a 500.
 *
 * Queueing does put the reset token in the queue payload, where an inline send
 * would have kept it in memory. It is the plaintext half of a pair whose other
 * half is hashed in `password_reset_tokens`, it is good for sixty minutes
 * (`auth.passwords.users.expire`), and Redis is not published outside the
 * compose network. Anyone able to read the queue can already read the database
 * this token unlocks nothing without.
 */
final class ResetPassword extends FrameworkResetPassword implements ShouldQueueAfterCommit
{
    use Queueable;

    /** @see VerifyEmail::$tries for why four and not three. */
    public int $tries = 4;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }
}
