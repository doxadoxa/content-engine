<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\StripeBillingProvider;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Expectation;
use Mockery\MockInterface;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

/**
 * Ending a subscription at Stripe when its project is archived, against a
 * transport that records what would have been sent.
 */
final class StripeCancelSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{method: string, path: string}> */
    private array $requests = [];

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function test_it_deletes_the_subscription_at_stripe(): void
    {
        [$owner, $project] = $this->subscribed();
        $this->stripe(status: 'active');

        $this->assertTrue(app(StripeBillingProvider::class)->cancelSubscription($owner, $project));

        $this->assertSame([
            ['method' => 'get', 'path' => '/v1/subscriptions/sub_x'],
            ['method' => 'delete', 'path' => '/v1/subscriptions/sub_x'],
        ], $this->requests);
    }

    public function test_a_pending_schedule_is_released_before_the_delete(): void
    {
        [$owner, $project, $local] = $this->subscribed();
        $local->forceFill(['stripe_schedule_id' => 'sched_x'])->save();
        $this->stripe(status: 'active');

        $this->assertTrue(app(StripeBillingProvider::class)->cancelSubscription($owner, $project));

        $this->assertSame([
            ['method' => 'get', 'path' => '/v1/subscriptions/sub_x'],
            ['method' => 'get', 'path' => '/v1/subscription_schedules/sched_x'],
            ['method' => 'post', 'path' => '/v1/subscription_schedules/sched_x/release'],
            ['method' => 'delete', 'path' => '/v1/subscriptions/sub_x'],
        ], $this->requests);
        $this->assertNull($local->refresh()->stripe_schedule_id);
    }

    public function test_an_already_canceled_subscription_is_left_alone(): void
    {
        [$owner, $project] = $this->subscribed();
        $this->stripe(status: 'canceled');

        $this->assertTrue(app(StripeBillingProvider::class)->cancelSubscription($owner, $project));

        $this->assertSame([['method' => 'get', 'path' => '/v1/subscriptions/sub_x']], $this->requests);
    }

    public function test_a_subscription_belonging_to_another_customer_is_refused(): void
    {
        [$owner, $project] = $this->subscribed();
        $this->stripe(status: 'active', customer: 'cus_somebody_else');

        try {
            app(StripeBillingProvider::class)->cancelSubscription($owner, $project);
            $this->fail('Expected a refusal.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not belong', $e->getMessage());
        }

        $this->assertSame([['method' => 'get', 'path' => '/v1/subscriptions/sub_x']], $this->requests);
    }

    /** @return array{User, Project, ProjectSubscription} */
    private function subscribed(): array
    {
        config(['cashier.secret' => 'sk_test_no_network']);
        $project = Project::factory()->unbilled()->create();
        $owner = User::factory()->create(['stripe_id' => 'cus_x']);
        $local = ProjectSubscription::factory()->forProject($project)->create([
            'billing_user_id' => $owner->id, 'stripe_id' => 'sub_x',
        ]);

        return [$owner, $project, $local];
    }

    private function stripe(string $status, string $customer = 'cus_x'): void
    {
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        /** @var Expectation $expectation */
        $expectation = $client->shouldReceive('request');
        $expectation->andReturnUsing(function ($method, $url) use ($status, $customer): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $this->requests[] = ['method' => $method, 'path' => $path];
            $schedule = ['id' => 'sched_x', 'object' => 'subscription_schedule', 'customer' => $customer, 'subscription' => 'sub_x', 'released_subscription' => null, 'status' => 'active'];
            $response = match (true) {
                $path === '/v1/subscriptions/sub_x' => ['id' => 'sub_x', 'object' => 'subscription', 'customer' => $customer, 'status' => $method === 'delete' ? 'canceled' : $status],
                str_ends_with($path, '/release') => [...$schedule, 'status' => 'released', 'released_subscription' => 'sub_x'],
                $path === '/v1/subscription_schedules/sched_x' => $schedule,
                default => throw new RuntimeException('Unexpected Stripe request: '.$path),
            };

            return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
    }
}
