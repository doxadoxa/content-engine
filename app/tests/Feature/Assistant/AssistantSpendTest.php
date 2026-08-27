<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Ai\Assistant\Assistant;
use App\Ai\Contracts\ConversationGateway;
use App\Ai\ConversationFailed;
use App\Ai\ConversationRequest;
use App\Ai\ConversationResponse;
use App\Ai\ConversationUsage;
use App\Ai\FakeConversationGateway;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Support\Metering\ProjectSpend;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The hole in the meter, and the tests that keep it shut.
 *
 * `ConversationGateway`'s docblock has always promised that "a conversation is
 * metered exactly like a pipeline step", and for as long as the assistant
 * existed that was true of the gateway and false of everything downstream: the
 * tokens were written and the money was not. These assertions are the promise.
 */
final class AssistantSpendTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeConversationGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->project = Project::factory()->create();
        app(CurrentProject::class)->set($this->project);

        $gateway = app(ConversationGateway::class);
        $this->assertInstanceOf(FakeConversationGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    #[Test]
    public function a_turn_records_what_it_cost_and_not_only_what_it_used(): void
    {
        $this->gateway->willReply('Here is what I would do.');

        $said = app(Assistant::class)->reply($this->project, $this->thread(), 'What should we write?');

        // The fake reports 12 in and 34 out on `fake-conversation`, priced in
        // config/models.php at $1 and $2 per million: 12 + 68 = 80 micro-USD.
        $this->assertSame(12, $said->input_tokens);
        $this->assertSame(34, $said->output_tokens);
        $this->assertSame(80, $said->cost_micros);
        $this->assertSame('fake', $said->provider);
        $this->assertSame('fake-conversation', $said->model);
        $this->assertSame(config('models.prices.version'), $said->price_list_version);
    }

    #[Test]
    public function the_two_rows_that_call_no_model_are_priced_at_nothing(): void
    {
        $this->gateway->willReply('Done.', [['name' => 'read_content_state']]);

        app(Assistant::class)->reply($this->project, $this->thread(), 'How are we doing?');

        $asked = AssistantMessage::query()->where('role', AssistantMessage::USER)->sole();
        $ran = AssistantMessage::query()->where('role', AssistantMessage::TOOL)->sole();

        // Null provider rather than zero cost, and the difference is the whole
        // reason the column is nullable: these rows did not make a cheap call,
        // they made no call. A report that counted them would divide one
        // conversation's price by three.
        $this->assertNull($asked->provider);
        $this->assertNull($ran->provider);
        $this->assertSame(0, $asked->cost_micros);
        $this->assertSame(0, $ran->cost_micros);
    }

    #[Test]
    public function a_turn_that_broke_on_the_last_leg_still_reports_what_it_spent(): void
    {
        // The case the failure path exists for: the tools ran, the money was
        // spent, and the request asking for the words to say about them fell
        // over. A meter that only counts turns which came back is a meter a
        // flapping provider walks straight through.
        $this->swapGateway(new class implements ConversationGateway
        {
            public function converse(ConversationRequest $request): ConversationResponse
            {
                throw new ConversationFailed(
                    'the provider hung up',
                    [['name' => 'read_content_state', 'arguments' => [], 'result' => ['ok' => true]]],
                    usage: new ConversationUsage('fake', 'fake-conversation', 500, 100, 12),
                );
            }
        });

        $said = app(Assistant::class)->reply($this->project, $this->thread(), 'Write something.');

        $this->assertStringContainsString('could not finish', $said->body ?? '');
        $this->assertSame(500, $said->input_tokens);
        $this->assertSame(700, $said->cost_micros);
        $this->assertSame('fake-conversation', $said->model);
    }

    #[Test]
    public function a_turn_that_broke_before_spending_anything_is_priced_at_nothing(): void
    {
        $this->swapGateway(new class implements ConversationGateway
        {
            public function converse(ConversationRequest $request): ConversationResponse
            {
                throw new ConversationFailed('the provider refused the connection');
            }
        });

        $said = app(Assistant::class)->reply($this->project, $this->thread(), 'Write something.');

        // Nothing rather than a zero-token row with a provider on it: a call
        // that was never made must not read as a call that was free.
        $this->assertNull($said->provider);
        $this->assertSame(0, $said->cost_micros);
        $this->assertSame(0, $said->input_tokens);
    }

    #[Test]
    public function what_a_project_cost_is_both_doors_and_not_only_the_engine(): void
    {
        PipelineRun::factory()->for($this->project)->create(['cost_micros' => 4_000]);

        $this->gateway->willReply('Understood.');
        app(Assistant::class)->reply($this->project, $this->thread(), 'Hello.');

        $spend = ProjectSpend::for($this->project, now()->subDay());

        $this->assertSame(4_000, $spend->pipelineMicros);
        $this->assertSame(80, $spend->assistantMicros);
        $this->assertSame(4_080, $spend->totalMicros());
    }

    #[Test]
    public function spend_outside_the_window_is_not_counted(): void
    {
        $this->gateway->willReply('Old news.');
        app(Assistant::class)->reply($this->project, $this->thread(), 'Hello.');

        AssistantMessage::query()->update(['created_at' => now()->subDays(40)]);
        PipelineRun::factory()->for($this->project)->create([
            'cost_micros' => 9_000,
            'created_at' => now()->subDays(40),
        ]);

        $this->assertSame(0, ProjectSpend::total($this->project, now()->subDays(30)));
    }

    #[Test]
    public function another_projects_conversation_is_not_on_this_projects_bill(): void
    {
        // The failure that would matter most: a ceiling that pauses the wrong
        // tenant, or one that never trips because it is reading somebody else's
        // quiet month.
        $other = Project::factory()->create();

        $this->gateway->willReply('Not your conversation.');

        app(CurrentProject::class)->run($other, function () use ($other): void {
            app(Assistant::class)->reply($other, AssistantThread::start('Theirs'), 'Hello.');
        });

        $this->assertSame(0, ProjectSpend::total($this->project, now()->subDay()));
        $this->assertSame(80, ProjectSpend::total($other, now()->subDay()));
    }

    private function thread(): AssistantThread
    {
        return AssistantThread::start('Planning');
    }

    private function swapGateway(ConversationGateway $gateway): void
    {
        $this->app->instance(ConversationGateway::class, $gateway);
    }
}
