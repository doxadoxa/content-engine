<?php

declare(strict_types=1);

namespace Tests\Feature\Publishing;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Enums\WebhookEvent;
use App\Models\Channel;
use App\Models\Project;
use App\Models\WebhookDelivery;
use App\Publishing\ConnectionHealth;
use App\Publishing\WebhookPayload;
use App\Support\Tenancy\CurrentProject;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The five answers to "does my website connection work?". */
final class ConnectionHealthTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30T12:00:00Z'));
        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);
        $this->channel = Channel::factory()->create([
            'type' => ChannelType::Webhook,
            'is_enabled' => true,
            'config' => ['endpoint' => 'https://receiver.test/hook'],
            'secret' => 'shared-secret',
            'verified_at' => null,
        ]);
    }

    #[Test]
    public function a_website_never_tested_says_so(): void
    {
        $health = ConnectionHealth::for($this->channel);

        $this->assertSame('untested', $health['state']);
        $this->assertSame('Not tested yet', $health['headline']);
        $this->assertNull($health['checked_at']);
    }

    #[Test]
    public function a_test_waiting_to_be_sent_is_testing(): void
    {
        $this->ping(DeliveryStatus::Pending);

        $health = ConnectionHealth::for($this->channel);

        $this->assertSame('testing', $health['state']);
        $this->assertSame('2026-09-30T12:00:00+00:00', $health['checked_at']);
    }

    #[Test]
    public function a_test_that_never_ran_stops_saying_testing(): void
    {
        $this->ping(DeliveryStatus::Pending);
        $this->travel(6)->minutes();

        $health = ConnectionHealth::for($this->channel);

        $this->assertSame('failed', $health['state']);
        $this->assertSame("The test didn't run. Try again in a few minutes.", $health['detail']);
    }

    #[Test]
    public function a_refused_test_explains_why_in_plain_words(): void
    {
        $this->ping(DeliveryStatus::DeadLetter, 401, 'receiver refused with 401');

        $health = ConnectionHealth::for($this->channel);

        $this->assertSame('failed', $health['state']);
        $this->assertSame("Couldn't connect", $health['headline']);
        $this->assertStringContainsString("rejected Avyo's signature", (string) $health['detail']);
    }

    #[Test]
    public function an_old_test_still_on_the_retry_ladder_is_a_failure_not_testing(): void
    {
        // Written before tests stopped retrying: its first attempt got a 503
        // and its next rung is hours away.
        $this->ping(DeliveryStatus::Retrying, 503, 'receiver answered 503');

        $health = ConnectionHealth::for($this->channel);

        $this->assertSame('failed', $health['state']);
        $this->assertSame('Your website had an error (503).', $health['detail']);
    }

    #[Test]
    public function a_passed_test_is_connected_with_the_time_it_passed(): void
    {
        $this->ping(DeliveryStatus::Delivered, 200);
        $this->channel->forceFill(['verified_at' => now()])->save();

        $health = ConnectionHealth::for($this->channel);

        $this->assertSame('connected', $health['state']);
        $this->assertSame('Connected', $health['headline']);
        $this->assertSame('2026-09-30T12:00:00+00:00', $health['checked_at']);
    }

    #[Test]
    public function an_older_failure_does_not_outweigh_a_newer_pass(): void
    {
        $this->ping(DeliveryStatus::DeadLetter, 500, 'receiver answered 500');
        $this->travel(1)->minute();
        $this->channel->forceFill(['verified_at' => now()])->save();

        $this->assertSame('connected', ConnectionHealth::for($this->channel)['state']);
    }

    #[Test]
    public function a_newer_failure_outweighs_an_older_pass(): void
    {
        $this->channel->forceFill(['verified_at' => now()])->save();
        $this->travel(1)->minute();
        $this->ping(DeliveryStatus::DeadLetter, 404, 'receiver refused with 404');

        $health = ConnectionHealth::for($this->channel);

        $this->assertSame('failed', $health['state']);
        $this->assertStringContainsString('Check the webhook address', (string) $health['detail']);
    }

    #[Test]
    public function a_paused_website_is_paused_whatever_its_tests_said(): void
    {
        $this->ping(DeliveryStatus::DeadLetter, 500, 'receiver answered 500');
        $this->channel->forceFill(['is_enabled' => false])->save();

        $this->assertSame('paused', ConnectionHealth::for($this->channel)['state']);
    }

    #[Test]
    public function article_deliveries_are_not_mistaken_for_tests(): void
    {
        $this->channel->forceFill(['verified_at' => now()])->save();
        WebhookDelivery::query()->create([
            'channel_id' => $this->channel->id,
            'content_item_id' => null,
            'delivery_id' => WebhookPayload::newDeliveryId(),
            'status' => DeliveryStatus::DeadLetter->value,
            'response_code' => 500,
            'payload_snapshot' => ['event' => WebhookEvent::Published->value],
        ]);

        $this->assertSame('connected', ConnectionHealth::for($this->channel)['state']);
    }

    private function ping(DeliveryStatus $status, ?int $code = null, ?string $error = null): WebhookDelivery
    {
        $deliveryId = WebhookPayload::newDeliveryId();

        return WebhookDelivery::query()->create([
            'channel_id' => $this->channel->id,
            'content_item_id' => null,
            'delivery_id' => $deliveryId,
            'status' => $status->value,
            'response_code' => $code,
            'error' => $error,
            'payload_snapshot' => WebhookPayload::ping($this->project, $deliveryId),
        ]);
    }
}
