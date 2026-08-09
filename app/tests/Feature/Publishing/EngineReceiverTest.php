<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Persistance\EngineReceiver\EngineContent;
use Persistance\EngineReceiver\EngineDelivery;
use Persistance\EngineReceiver\WebhookSignature;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The `engine-receiver` package, standing on its own (§6.3).
 *
 * The package is installed as a path repository and registers its own route and
 * migration, so these tests are exit criterion 1 in the most literal reading
 * available inside one suite: nothing here wires anything up, and the endpoint
 * is live because the package is installed.
 */
final class EngineReceiverTest extends TestCase
{
    use RefreshDatabase;

    private string $secret = 'shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('engine-receiver.secret', $this->secret);
    }

    // ------------------------------------------------------- exit criterion 1

    #[Test]
    public function a_receiver_installed_from_the_package_accepts_a_publication(): void
    {
        $response = $this->deliver($this->publication());

        $response->assertOk()->assertJsonStructure(['status', 'public_url']);

        $stored = EngineContent::query()->firstOrFail();

        $this->assertSame('Como limpar janelas', $stored->title);
        $this->assertSame('pt-PT', $stored->locale);
        $this->assertSame('<h2>Porquê</h2>', $stored->getAttribute('html'));
        $this->assertSame('HowTo', $stored->getAttribute('json_ld')['@type']);
    }

    #[Test]
    public function it_answers_with_a_public_url(): void
    {
        $this->deliver($this->publication())
            ->assertOk()
            ->assertJsonPath('public_url', url('/pt-PT/como-limpar-janelas'));
    }

    #[Test]
    public function the_public_url_can_be_overridden_by_the_site(): void
    {
        config()->set('engine-receiver.public_url', fn (array $content): string => 'https://site.test/blog/'.$content['slug']);

        $this->deliver($this->publication())
            ->assertJsonPath('public_url', 'https://site.test/blog/como-limpar-janelas');
    }

    #[Test]
    public function a_ping_is_answered_without_content(): void
    {
        $this->deliver([
            'contract' => 1,
            'event' => 'ping',
            'delivery_id' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('status', 'ok');

        $this->assertSame(0, EngineContent::query()->count());
    }

    // ------------------------------------------------------- exit criterion 2

    #[Test]
    public function the_same_delivery_id_never_creates_a_second_publication(): void
    {
        $payload = $this->publication();

        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertStatus(409);

        // §2 of the contract: a repeat is normal, and it is not a publication.
        $this->assertSame(1, EngineContent::query()->count());
        $this->assertSame(1, EngineDelivery::query()->count());
    }

    #[Test]
    public function an_update_replaces_the_row_rather_than_adding_one(): void
    {
        $this->deliver($this->publication())->assertOk();

        $updated = $this->publication();
        $updated['event'] = 'content.updated';
        $updated['content']['title'] = 'Como limpar janelas, revisto';

        $this->deliver($updated)->assertOk();

        // Same unit, same language: one row. Without `content.updated` the
        // refresh loop of phase 9 has no way to land (§1.3).
        $this->assertSame(1, EngineContent::query()->count());
        $this->assertSame('Como limpar janelas, revisto', EngineContent::query()->firstOrFail()->title);
    }

    #[Test]
    public function two_locales_of_one_unit_are_two_rows_that_know_each_other(): void
    {
        $this->deliver($this->publication())->assertOk();

        $english = $this->publication();
        $english['content']['id'] = '01kzunit000000000000000001';
        $english['content']['locale'] = 'en';
        $english['content']['slug'] = 'how-to-clean-windows';

        $this->deliver($english)->assertOk();

        $this->assertSame(2, EngineContent::query()->count());

        // The group is what an hreflang block is built from.
        $portuguese = EngineContent::query()->where('locale', 'pt-PT')->firstOrFail();
        $this->assertSame(1, $portuguese->siblings()->count());
    }

    #[Test]
    public function a_delete_removes_the_row(): void
    {
        $this->deliver($this->publication())->assertOk();

        $delete = $this->publication();
        $delete['event'] = 'content.deleted';
        $delete['content'] = [
            'id' => '01kzunit000000000000000000',
            'locale_group_id' => '01kzgroup00000000000000000',
            'locale' => 'pt-PT',
            'slug' => 'como-limpar-janelas',
        ];

        $this->deliver($delete)->assertOk();

        $this->assertSame(0, EngineContent::query()->count());
    }

    // ------------------------------------------------------- exit criterion 3

    #[Test]
    public function a_signature_from_the_wrong_secret_is_refused(): void
    {
        $this->deliver($this->publication(), secret: 'somebody-elses-secret')
            ->assertUnauthorized();

        $this->assertSame(0, EngineContent::query()->count());
    }

    #[Test]
    public function an_expired_timestamp_is_refused(): void
    {
        // Correctly signed, but signed an hour ago: a recording of a valid
        // request must not be replayable for ever.
        $this->deliver($this->publication(), timestamp: time() - 3_600)
            ->assertUnauthorized();

        $this->assertSame(0, EngineContent::query()->count());
    }

    #[Test]
    public function a_timestamp_far_in_the_future_is_refused(): void
    {
        $this->deliver($this->publication(), timestamp: time() + 3_600)
            ->assertUnauthorized();
    }

    #[Test]
    public function a_request_with_no_signature_at_all_is_refused(): void
    {
        $this->postJson('/'.config('engine-receiver.path'), $this->publication())
            ->assertUnauthorized();
    }

    #[Test]
    public function a_tampered_body_is_refused(): void
    {
        $payload = $this->publication();
        $body = (string) json_encode($payload);
        $timestamp = time();

        // Signed correctly, then the body was edited in flight.
        $tampered = str_replace('Como limpar janelas', 'Something else entirely', $body);

        $this->call(
            'POST',
            '/'.config('engine-receiver.path'),
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->secret,
                'HTTP_X_ENGINE_SIGNATURE' => WebhookSignature::compute($this->secret, $timestamp, $body),
                'HTTP_X_ENGINE_TIMESTAMP' => (string) $timestamp,
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $tampered,
        )->assertUnauthorized();
    }

    #[Test]
    public function an_unconfigured_receiver_refuses_rather_than_accepts(): void
    {
        config()->set('engine-receiver.secret', null);

        // Answering 200 while unconfigured would look connected and be open.
        $this->deliver($this->publication())->assertStatus(503);
    }

    #[Test]
    public function a_signed_request_with_no_delivery_id_is_rejected(): void
    {
        $payload = $this->publication();
        unset($payload['delivery_id']);

        $this->deliver($payload)->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    private function deliver(
        array $payload,
        ?string $secret = null,
        ?int $timestamp = null,
    ): TestResponse {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secret ??= $this->secret;
        $timestamp ??= time();

        return $this->call(
            'POST',
            '/'.config('engine-receiver.path'),
            server: [
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->secret,
                'HTTP_X_ENGINE_SIGNATURE' => WebhookSignature::compute($secret, $timestamp, $body),
                'HTTP_X_ENGINE_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_ENGINE_EVENT' => (string) ($payload['event'] ?? ''),
                'CONTENT_TYPE' => 'application/json',
            ],
            content: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function publication(array $overrides = []): array
    {
        return [
            'contract' => 1,
            'event' => 'content.published',
            'delivery_id' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(),
            'project' => ['slug' => 'cleaning-point', 'name' => 'Cleaning Point'],
            'content' => [
                'id' => '01kzunit000000000000000000',
                'locale_group_id' => '01kzgroup00000000000000000',
                'locale' => 'pt-PT',
                'slug' => 'como-limpar-janelas',
                'type' => 'how_to',
                'title' => 'Como limpar janelas',
                'summary' => 'Uma frase.',
                'markdown' => '## Porquê',
                'html' => '<h2>Porquê</h2>',
                'published_at' => now()->toIso8601String(),
                'images' => [],
                'json_ld' => ['@type' => 'HowTo'],
                'faq_json_ld' => ['@type' => 'FAQPage'],
                'author' => ['name' => 'Operator'],
                'internal_links' => [],
            ],
            ...$overrides,
        ];
    }
}
