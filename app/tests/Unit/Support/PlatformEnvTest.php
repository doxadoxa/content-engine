<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\PlatformEnv;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/*
 * `${APP_NAME}` in a variable a `.env` file never expanded.
 *
 * `.env.example` writes MAIL_FROM_NAME as a reference to APP_NAME. phpdotenv
 * resolves that when it reads a file; a hosting platform's environment is not
 * a file, so production got the placeholder verbatim and every verification
 * mail came from a sender named `${APP_NAME}`.
 */
final class PlatformEnvTest extends TestCase
{
    /**
     * What each variable was before the test, so APP_NAME from the container
     * is put back rather than removed.
     *
     * @var array<string, array{env: mixed, server: mixed, putenv: string|false}>
     */
    private array $before = [];

    protected function tearDown(): void
    {
        foreach ($this->before as $key => $was) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            if ($was['env'] !== null) {
                $_ENV[$key] = $was['env'];
            }

            if ($was['server'] !== null) {
                $_SERVER[$key] = $was['server'];
            }

            if ($was['putenv'] !== false) {
                putenv("{$key}={$was['putenv']}");
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function a_plain_value_is_returned_as_it_is(): void
    {
        $this->env('PLATFORM_ENV_TEST_NAME', 'Avyo');

        self::assertSame('Avyo', PlatformEnv::get('PLATFORM_ENV_TEST_NAME', 'fallback'));
    }

    #[Test]
    public function a_reference_is_resolved_against_the_environment(): void
    {
        $this->env('PLATFORM_ENV_TEST_APP', 'Avyo');
        $this->env('PLATFORM_ENV_TEST_NAME', '${PLATFORM_ENV_TEST_APP}');
        $this->env('PLATFORM_ENV_TEST_URL', '${PLATFORM_ENV_TEST_APP}/integrations/google/callback');

        self::assertSame('Avyo', PlatformEnv::get('PLATFORM_ENV_TEST_NAME'));
        self::assertSame('Avyo/integrations/google/callback', PlatformEnv::get('PLATFORM_ENV_TEST_URL'));
    }

    #[Test]
    public function a_reference_to_nothing_falls_back_rather_than_leaking_the_placeholder(): void
    {
        $this->env('PLATFORM_ENV_TEST_NAME', '${PLATFORM_ENV_TEST_MISSING}');

        self::assertSame('Avyo', PlatformEnv::get('PLATFORM_ENV_TEST_NAME', 'Avyo'));
    }

    #[Test]
    public function unset_or_blank_is_the_default(): void
    {
        $this->env('PLATFORM_ENV_TEST_BLANK', '');

        self::assertSame('Avyo', PlatformEnv::get('PLATFORM_ENV_TEST_UNSET', 'Avyo'));
        self::assertSame('Avyo', PlatformEnv::get('PLATFORM_ENV_TEST_BLANK', 'Avyo'));
        self::assertNull(PlatformEnv::get('PLATFORM_ENV_TEST_UNSET'));
    }

    #[Test]
    public function the_mail_sender_is_named_after_the_app_rather_than_the_placeholder(): void
    {
        // The production case, end to end through the config file.
        $this->env('APP_NAME', 'Avyo');
        $this->env('MAIL_FROM_NAME', '${APP_NAME}');
        $this->env('MAIL_REPLY_TO_NAME', '${APP_NAME}');

        // `require`, not require_once: evaluated against the environment above.
        $mail = require __DIR__.'/../../../config/mail.php';

        self::assertSame('Avyo', $mail['from']['name']);
        self::assertSame('Avyo', $mail['reply_to']['name']);
    }

    /** All three places tests/bootstrap.php writes to, since which adapter
     *  answers first depends on which of them is populated. */
    private function env(string $key, string $value): void
    {
        $this->before[$key] ??= [
            'env' => $_ENV[$key] ?? null,
            'server' => $_SERVER[$key] ?? null,
            'putenv' => getenv($key),
        ];

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }
}
