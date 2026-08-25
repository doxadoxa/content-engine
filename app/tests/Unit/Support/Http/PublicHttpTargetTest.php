<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Http;

use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class PublicHttpTargetTest extends TestCase
{
    #[Test]
    #[DataProvider('unsafeAddresses')]
    public function it_rejects_non_public_addresses(string $url): void
    {
        $this->expectException(UnsafePublicUrl::class);

        app(PublicHttpTarget::class)->validate($url);
    }

    /** @return array<string, array{string}> */
    public static function unsafeAddresses(): array
    {
        return [
            'loopback' => ['http://127.0.0.1/admin'],
            'private' => ['https://10.0.0.2/hook'],
            'link-local metadata' => ['http://169.254.169.254/latest/meta-data'],
            'IPv6 loopback' => ['http://[::1]/'],
            'credentials' => ['https://user:pass@8.8.8.8/'],
            'unexpected port' => ['https://8.8.8.8:8443/'],
        ];
    }

    #[Test]
    public function it_accepts_a_public_address_and_exposes_its_origin(): void
    {
        $target = app(PublicHttpTarget::class)->validate('https://8.8.8.8/path');

        $this->assertSame('https://8.8.8.8', $target->origin);
        $this->assertSame(443, $target->port);
    }

    #[Test]
    public function it_can_require_https(): void
    {
        config()->set('security.outbound.require_https', true);

        $this->expectException(UnsafePublicUrl::class);

        app(PublicHttpTarget::class)->validate('http://8.8.8.8/path');
    }

    /**
     * A resolver having a bad moment is a refusal, not a stack trace.
     *
     * `dns_get_record()` warns on SERVFAIL, and under an error handler that
     * promotes warnings that warning is thrown — skipping both the
     * `gethostbynamel` fallback and this deliberate message. CI found it as a
     * flake in the onboarding wizard, where a temporary DNS error failed the
     * build; the same path in production is a 500 on a URL that is fine.
     *
     * `.invalid` is reserved by RFC 2606 precisely so that it never resolves,
     * which makes it the one host that exercises the failed-lookup branch
     * without depending on what the network is doing today.
     */
    #[Test]
    public function a_host_that_will_not_resolve_is_refused_rather_than_raised(): void
    {
        config()->set('security.outbound.allow_unresolved_hosts', false);

        $this->expectException(UnsafePublicUrl::class);
        $this->expectExceptionMessage('could not be resolved');

        app(PublicHttpTarget::class)->validate('https://nothing-here.invalid/feed');
    }

    #[Test]
    public function it_enforces_same_origin_when_requested(): void
    {
        config()->set('security.outbound.allow_unresolved_hosts', true);

        $this->expectException(UnsafePublicUrl::class);

        app(PublicHttpTarget::class)->validate(
            'https://cdn.example.test/article',
            'https://www.example.test/sitemap.xml',
        );
    }
}
