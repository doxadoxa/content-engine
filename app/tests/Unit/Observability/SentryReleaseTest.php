<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/*
 * What the server reports as its release, and what it reports when it has none.
 *
 * The production image sets SENTRY_RELEASE whether or not a release was passed
 * to the build, because a Dockerfile has no way to set a variable
 * conditionally. So every image built without `--build-arg SENTRY_RELEASE`
 * hands this config an empty string, and env() returns '' rather than null for
 * a variable that is present and blank.
 *
 * An empty-string release is worse than no release rather than equivalent to
 * it: the SDK would file every server event under a release named nothing,
 * which reads in the Sentry UI as a deliberate answer instead of a missing
 * one, and groups every deployment ever made into a single bucket. The whole
 * difference is one `?:` in a config file, which is exactly the kind of
 * character that gets tidied away by someone who cannot see what it is for.
 *
 * The release also has to survive as the *same string* the browser bundle was
 * compiled with and the source maps were uploaded against, or Sentry holds
 * maps it will never apply to a PHP stack trace. That agreement is made in
 * .github/workflows/build-image.yml and app/Dockerfile; this file guards the
 * one end of it that is expressible in PHP.
 */
final class SentryReleaseTest extends TestCase
{
    private const KEY = 'SENTRY_RELEASE';

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY], $_SERVER[self::KEY]);
        putenv(self::KEY);

        parent::tearDown();
    }

    #[Test]
    public function the_release_passed_to_the_build_is_what_the_server_reports(): void
    {
        // A full 40-character sha, which is what the workflow passes and what
        // the sha- image tag is built from. Nothing here depends on the length,
        // but a short sha in the fixture would quietly suggest one is expected.
        $sha = '8deaf1e6a0b3c5d7e9f1a3b5c7d9e1f3a5b7c9d1';

        self::assertSame($sha, $this->releaseGiven($sha));
    }

    #[Test]
    public function an_image_built_without_a_release_reports_none_rather_than_a_blank_one(): void
    {
        self::assertNull($this->releaseGiven(''));
    }

    #[Test]
    public function an_environment_without_the_variable_at_all_reports_none(): void
    {
        self::assertNull($this->release());
    }

    /** Sets the variable the three ways tests/bootstrap.php sets one, since the
     *  adapter that answers env() first depends on which of them is populated. */
    private function releaseGiven(string $value): ?string
    {
        $_ENV[self::KEY] = $value;
        $_SERVER[self::KEY] = $value;
        putenv(self::KEY.'='.$value);

        return $this->release();
    }

    private function release(): ?string
    {
        // `require`, not require_once: each case needs the file evaluated again
        // against the environment it just set.
        return (require __DIR__.'/../../../config/sentry.php')['release'];
    }
}
