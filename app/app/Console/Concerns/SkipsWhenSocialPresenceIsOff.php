<?php

declare(strict_types=1);

namespace App\Console\Concerns;

/**
 * The first line of every command the social presence owns.
 *
 * `routes/console.php` already stops these from being scheduled when
 * `SOCIAL_PRESENCE_ENABLED` is off, so nothing here fires on a timer. This is
 * for the other way in: `php artisan social:listen` typed by hand, by an
 * operator who does not know the feature is off, or by a runbook written before
 * it was turned off, or by a deployment script that calls it after a release.
 *
 * Exit zero, and this is the part that is a decision rather than an
 * implementation detail. A configuration somebody chose on purpose is not a
 * failure, and these commands are the sort that end up in cron wrappers and CI
 * steps that check the exit code — a non-zero status turns "we do not do social
 * here" into an alert every hour, which is exactly the noise the switch was
 * added to remove. The distinction the existing commands already draw is the
 * one being followed: `ThreadsRenewCommand` says "No project has a Threads
 * connection." and exits zero, while a project slug that does not exist is a
 * FAILURE, because that one is somebody's mistake.
 *
 * The message names the variable rather than describing the state, because the
 * only useful thing to tell a person who ran this by hand is where the answer
 * lives.
 */
trait SkipsWhenSocialPresenceIsOff
{
    /**
     * True when this deployment does not run the social presence, having said
     * so on the way past.
     */
    protected function socialPresenceIsOff(): bool
    {
        if (config('social.enabled')) {
            return false;
        }

        $this->components->info(
            'The social presence is off on this deployment. Set SOCIAL_PRESENCE_ENABLED=true to turn it on.'
        );

        return true;
    }
}
