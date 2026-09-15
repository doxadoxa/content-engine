<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Request;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Throwable;

/**
 * Socialite's Google driver with the one network call replaced.
 *
 * A subclass rather than a mock, and the difference matters twice. It is the
 * real class, so a signature the controller relies on cannot drift out from
 * under the tests without them failing to compile; and `user()` is the only
 * thing overridden, so everything the controller does *around* it — building
 * the redirect URL, the state check that a mock would happily skip — is the
 * production code path.
 *
 * Installed with `Socialite::extend('google', ...)`, which is the manager's own
 * seam for exactly this and needs no facade mocking.
 */
final class FakeGoogleProvider extends GoogleProvider
{
    public function __construct(
        Request $request,
        private readonly ?SocialiteUser $account = null,
        private readonly ?Throwable $failure = null,
    ) {
        parent::__construct($request, 'test-client-id', 'test-client-secret', 'https://example.test/auth/google/callback');
    }

    /**
     * What came back from Google — or what went wrong on the way.
     *
     * @return SocialiteUser
     */
    public function user()
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->account ?? new SocialiteUser;
    }
}
