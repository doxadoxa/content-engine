<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\PlanCatalog;
use App\Billing\StripeBillingProvider;
use App\Models\Project;
use App\Models\ProjectSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\Expectation;
use Mockery\MockInterface;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Tests\TestCase;

final class StripePlanScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(CurlClient::instance());
        parent::tearDown();
    }

    public function test_stripe_schedule_preserves_current_phase_and_can_be_recreated_after_cancellation(): void
    {
        config(['cashier.secret' => 'sk_test_no_network', 'billing.plans.starter.stripe_price' => 'price_starter']);
        $project = Project::factory()->unbilled()->create();
        $owner = User::factory()->create(['stripe_id' => 'cus_test']);
        $local = ProjectSubscription::factory()->forProject($project)->create([
            'billing_user_id' => $owner->id, 'stripe_id' => 'sub_test', 'plan' => 'growth',
        ]);
        $phase = ['start_date' => $local->periodStart()->timestamp, 'end_date' => $local->period_ends_at->timestamp,
            'items' => [['price' => 'price_growth', 'quantity' => 1, 'tax_rates' => ['txr_one']]],
            'discounts' => [['discount' => 'di_saved']], 'metadata' => ['project_id' => $project->id, 'plan' => 'growth']];
        $requests = [];
        $generation = 0;
        $scheduleId = null;
        $released = false;
        $loseCreateResponse = true;
        /** @var ClientInterface&MockInterface $client */
        $client = Mockery::mock(ClientInterface::class);
        /** @var Expectation $expectation */
        $expectation = $client->shouldReceive('request');
        $expectation->andReturnUsing(function ($method, $url, $headers, $params) use (&$requests, &$generation, &$scheduleId, &$released, &$loseCreateResponse, $phase): array {
            $path = (string) parse_url($url, PHP_URL_PATH);
            $requests[] = ['method' => $method, 'path' => $path, 'headers' => $headers, 'params' => $params];
            $schedule = ['id' => $scheduleId, 'object' => 'subscription_schedule', 'customer' => 'cus_test', 'subscription' => 'sub_test', 'released_subscription' => null, 'status' => $released ? 'released' : 'active', 'phases' => [$phase]];
            $response = match (true) {
                $path === '/v1/prices/price_starter' => ['id' => 'price_starter', 'object' => 'price', 'active' => true, 'currency' => 'usd', 'unit_amount' => 2900, 'type' => 'recurring', 'recurring' => ['interval' => 'month', 'interval_count' => 1]],
                $path === '/v1/subscriptions/sub_test' => ['id' => 'sub_test', 'object' => 'subscription', 'customer' => 'cus_test', 'status' => 'active', 'cancel_at_period_end' => false, 'schedule' => $released ? null : $scheduleId,
                    'items' => ['object' => 'list', 'data' => [['id' => 'si_one', 'object' => 'subscription_item', 'current_period_end' => $phase['end_date'], 'price' => ['id' => 'price_growth']]]]],
                $method === 'post' && $path === '/v1/subscription_schedules' => (function () use (&$generation, &$scheduleId, &$released, &$loseCreateResponse, $schedule): array {
                    if ($scheduleId !== null && ! $released) {
                        return $schedule;
                    }
                    $scheduleId = 'sched_'.++$generation;
                    $released = false;
                    if ($loseCreateResponse) {
                        $loseCreateResponse = false;
                        throw new \RuntimeException('Simulated lost response after Stripe created the schedule.');
                    }

                    return [...$schedule, 'id' => $scheduleId, 'status' => 'active'];
                })(),
                str_ends_with($path, '/release') => (function () use (&$released, $schedule): array {
                    $released = true;

                    return [...$schedule, 'status' => 'released', 'released_subscription' => 'sub_test'];
                })(),
                str_starts_with($path, '/v1/subscription_schedules/sched_') => $schedule,
                default => throw new \RuntimeException('Unexpected Stripe request: '.$path),
            };

            return [json_encode($response, JSON_THROW_ON_ERROR), 200, []];
        });
        ApiRequestor::setHttpClient($client);
        $provider = app(StripeBillingProvider::class);
        $plan = app(PlanCatalog::class)->get('starter');
        try {
            $provider->schedulePlanChange($owner, $project, $plan);
            $this->fail('Expected the simulated transport failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Simulated lost response', $exception->getMessage());
        }
        $this->assertNotNull($local->refresh()->stripe_schedule_generation);
        $this->assertNull($local->stripe_schedule_id);
        $this->assertSame('sched_1', $provider->schedulePlanChange($owner, $project, $plan));
        $firstGeneration = $local->refresh()->stripe_schedule_generation;
        $updates = array_values(array_filter($requests, static fn (array $request): bool => $request['method'] === 'post' && $request['path'] === '/v1/subscription_schedules/sched_1'));
        $this->assertCount(1, $updates);
        $payload = $updates[0]['params'];
        $this->assertSame('none', $payload['proration_behavior']);
        $this->assertSame('release', $payload['end_behavior']);
        $this->assertSame('price_growth', $payload['phases'][0]['items'][0]['price']);
        $this->assertSame($phase['discounts'], $payload['phases'][0]['discounts']);
        $this->assertSame(['txr_one'], $payload['phases'][0]['items'][0]['tax_rates']);
        $this->assertSame('price_starter', $payload['phases'][1]['items'][0]['price']);
        $this->assertSame(['txr_one'], $payload['phases'][1]['items'][0]['tax_rates']);
        $this->assertSame($phase['end_date'], $payload['phases'][1]['start_date']);
        $this->assertSame('starter', $payload['phases'][1]['metadata']['plan']);
        $this->assertTrue($provider->cancelPlanChange($owner, $project));
        $this->assertNull($local->refresh()->stripe_schedule_generation);
        $this->assertSame('sched_2', $provider->schedulePlanChange($owner, $project, $plan));
        $this->assertNotSame($firstGeneration, $local->refresh()->stripe_schedule_generation);
    }
}
