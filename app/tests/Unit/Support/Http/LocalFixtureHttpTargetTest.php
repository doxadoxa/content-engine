<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Http;

use App\Support\Http\PublicHttpClient;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class LocalFixtureHttpTargetTest extends TestCase
{
    public function test_named_fixture_is_process_only_and_cannot_expand_to_other_origins(): void
    {
        $target = PublicHttpTarget::forLocalFixture('wordpress');
        config(['security.outbound.require_https' => true]);
        $result = $target->validate('http://localhost:8093/wp-json/avyo/v1/objects/1', 'http://localhost:8093');
        $this->assertSame('http://localhost:8093', $result->origin);
        $this->assertNotEmpty($result->addresses);
        $this->assertArrayHasKey('curl', $result->httpOptions());
        foreach (['http://localhost:8094/', 'http://127.0.0.1:8093/', 'http://localhost:8093.evil.test/', 'http://user:pass@localhost:8093/', 'http://169.254.169.254/'] as $url) {
            try {
                $target->validate($url);
                $this->fail('The named fixture must not allow '.$url);
            } catch (UnsafePublicUrl) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(UnsafePublicUrl::class);
        (new PublicHttpTarget)->validate('http://localhost:8093/');
    }

    public function test_fixture_still_enforces_required_origin(): void
    {
        $target = PublicHttpTarget::forLocalFixture('wordpress');
        $this->expectException(UnsafePublicUrl::class);
        $target->validate('http://localhost:8093/', 'https://example.com');
    }

    public function test_custom_site_fixture_uses_its_own_exact_origin(): void
    {
        $target = PublicHttpTarget::forLocalFixture('cleaningpoint');
        $this->assertSame('http://localhost:8094', $target->validate('http://localhost:8094/en/service')->origin);
        $this->expectException(UnsafePublicUrl::class);
        $target->validate('http://localhost:8093/');
    }

    public function test_unknown_fixture_names_cannot_select_arbitrary_private_targets(): void
    {
        $this->expectException(UnsafePublicUrl::class);
        PublicHttpTarget::forLocalFixture('http://10.0.0.1');
    }

    public function test_factory_and_existing_instance_fail_closed_in_production(): void
    {
        $target = PublicHttpTarget::forLocalFixture('wordpress');
        $this->app->instance('env', 'production');
        $this->assertFalse($target->isLocalFixture('http://localhost:8093/'));
        try {
            $target->validate('http://localhost:8093/');
            $this->fail('A retained instance must not enable production fixture networking.');
        } catch (UnsafePublicUrl) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(UnsafePublicUrl::class);
        PublicHttpTarget::forLocalFixture('wordpress');
    }

    public function test_fixture_read_does_not_follow_a_redirect_to_another_local_service(): void
    {
        Http::fake(['http://localhost:8093/*' => Http::response('', 302, ['Location' => 'http://localhost:8094/private'])]);
        $client = new PublicHttpClient(PublicHttpTarget::forLocalFixture('wordpress'));
        try {
            $client->request('GET', 'http://localhost:8093/start', maxRedirects: 3, requiredOrigin: 'http://localhost:8093');
            $this->fail('A redirect must not extend the fixture exception.');
        } catch (UnsafePublicUrl) {
            Http::assertSentCount(1);
        }
    }
}
