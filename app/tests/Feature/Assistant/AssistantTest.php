<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Ai\Assistant\Assistant;
use App\Ai\Contracts\ConversationGateway;
use App\Ai\ConversationRequest;
use App\Ai\ConversationResponse;
use App\Ai\FakeConversationGateway;
use App\Enums\PostKind;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The assistant, and the one behaviour that distinguishes it from the form it
 * replaced: it is allowed to decline to act.
 */
final class AssistantTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeConversationGateway $gateway;

    private ?AssistantThread $thread = null;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->project = Project::factory()->create();
        $user = User::factory()->create();
        $user->projects()->attach($this->project);

        app(CurrentProject::class)->set($this->project);

        $gateway = app(ConversationGateway::class);

        // Not a courtesy assertion: it is the guard that the test environment
        // really is bound to the fake, which is what keeps the suite off the
        // network in every phase.
        $this->assertInstanceOf(FakeConversationGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    #[Test]
    public function a_vague_request_is_answered_with_a_question_and_makes_nothing(): void
    {
        // The complaint this whole feature came from: "how to clean a door"
        // started an article nobody had agreed to. A topic is not a decision,
        // and the model is told so — so the turn it takes is a question.
        $this->gateway->willReply('Are you chasing search traffic for that, or answering something customers keep asking?');

        $reply = app(Assistant::class)->reply($this->project, $this->thread(), 'how to clean a door');

        $this->assertSame(AssistantMessage::ASSISTANT, $reply->role);
        $this->assertStringContainsString('search traffic', (string) $reply->body);

        $this->assertSame(0, ContentItem::query()->roots()->count());
        $this->assertSame(0, PipelineRun::query()->count());
    }

    #[Test]
    public function a_specific_request_reaches_for_a_tool_and_the_work_really_starts(): void
    {
        $this->gateway->willReply(
            "I'll draft an explainer on cleaning wooden doors — it lands in the plan for you to approve.",
            [['name' => 'write_article', 'arguments' => ['topic' => 'how to clean wooden doors']]],
        );

        app(Assistant::class)->reply($this->project, $this->thread(), 'write an explainer about cleaning wooden doors');

        app(CurrentProject::class)->run($this->project, function (): void {
            $item = ContentItem::query()->roots()->firstOrFail();

            $this->assertSame('how to clean wooden doors', $item->target_query);
            $this->assertTrue(
                PipelineRun::query()->where('pipeline', 'generation')->exists(),
                'Asking for an article has to start the writing.',
            );
        });
    }

    #[Test]
    public function asking_for_a_post_really_starts_one(): void
    {
        $this->gateway->willReply(
            "I'll draft that as an opinion — it goes to Threads and X.",
            [[
                'name' => 'write_post',
                'arguments' => [
                    'thesis' => 'Limescale needs contact time, so we never rush a bathroom.',
                    'kind' => 'take',
                ],
            ]],
        );

        app(Assistant::class)->reply(
            $this->project,
            $this->thread(),
            'write a post about why we never rush bathrooms',
        );

        app(CurrentProject::class)->run($this->project, function (): void {
            $idea = ContentIdea::query()->firstOrFail();

            $this->assertSame(PostKind::Take, $idea->kind);
            // The kind decides the channels here exactly as it does in a
            // proposal, so a post the assistant wrote cannot do the one thing
            // every planned post is forbidden.
            $this->assertSame(['threads', 'x'], $idea->channels);

            // The run this pins: `content_studio` requires `content_plan_id`,
            // which only the studio's operations service supplies. Started
            // through the runner directly it failed validation every time, and
            // the tool reported the failure to the model as a refusal — so the
            // assistant would apologise for a post it had never tried to write.
            $run = PipelineRun::query()->where('pipeline', 'content_studio')->firstOrFail();

            $this->assertSame('generate_idea', $run->input['action']);
            $this->assertArrayHasKey('content_plan_id', $run->input);
            $this->assertSame((string) $idea->getKey(), $run->input['content_idea_id']);
        });
    }

    #[Test]
    public function a_tool_that_could_not_start_says_so_rather_than_claiming_success(): void
    {
        $this->gateway->willReply(
            'Starting that now.',
            [['name' => 'write_post', 'arguments' => ['thesis' => 'A point.', 'kind' => 'nonsense']]],
        );

        app(Assistant::class)->reply($this->project, $this->thread(), 'post something');

        app(CurrentProject::class)->run($this->project, function (): void {
            $tool = AssistantMessage::query()->where('role', AssistantMessage::TOOL)->firstOrFail();

            $this->assertFalse($tool->tool_result['ok']);
            $this->assertSame(0, ContentIdea::query()->count());
        });
    }

    #[Test]
    public function what_the_engine_did_is_a_row_of_its_own(): void
    {
        $this->gateway->willReply(
            'Drafting it now.',
            [['name' => 'write_article', 'arguments' => ['topic' => 'limescale in rented flats']]],
        );

        app(Assistant::class)->reply($this->project, $this->thread(), 'write about limescale in rented flats');

        app(CurrentProject::class)->run($this->project, function (): void {
            $rows = AssistantMessage::query()->oldest()->get();

            // The turn reads the way it happened: the question, the thing it
            // did, then what it said about it.
            $this->assertSame(
                [AssistantMessage::USER, AssistantMessage::TOOL, AssistantMessage::ASSISTANT],
                $rows->pluck('role')->all(),
            );

            $tool = $rows[1];
            $this->assertSame('write_article', $tool->tool_name);
            // The result carries the route to what was made, which is the half
            // that was missing when this was a form: work started and there was
            // nowhere to go and see it.
            $this->assertArrayHasKey('content_item_id', (array) $tool->tool_result);
        });
    }

    #[Test]
    public function the_question_survives_a_provider_that_fell_over(): void
    {
        $this->app->instance(ConversationGateway::class, new class implements ConversationGateway
        {
            public function converse(ConversationRequest $request): ConversationResponse
            {
                throw new RetryableStepFailure('the wire broke');
            }
        });

        $reply = app(Assistant::class)->reply($this->project, $this->thread(), 'what should we do about door cleaning?');

        app(CurrentProject::class)->run($this->project, function () use ($reply): void {
            // Somebody who typed three paragraphs and got an error should still
            // be looking at their three paragraphs.
            $this->assertSame(
                'what should we do about door cleaning?',
                AssistantMessage::query()->where('role', AssistantMessage::USER)->value('body'),
            );
            $this->assertStringContainsString('could not finish', (string) $reply->body);
        });
    }

    #[Test]
    public function the_thread_is_carried_back_so_the_assistant_remembers(): void
    {
        $this->gateway->willReply('Which channels do you want it on?');
        app(Assistant::class)->reply($this->project, $this->thread(), 'I want to talk about door cleaning');

        $this->gateway->willReply('Threads it is.');
        app(Assistant::class)->reply($this->project, $this->thread(), 'threads');

        $second = $this->gateway->requests[1];

        $this->assertSame('threads', $second->message);
        $this->assertSame(
            ['I want to talk about door cleaning', 'Which channels do you want it on?'],
            array_column($second->history, 'content'),
        );
    }

    #[Test]
    public function a_topic_longer_than_the_column_does_not_take_the_turn_down(): void
    {
        // A model handed a rambling instruction will hand a rambling topic
        // back. `target_query` and `title` are `varchar(255)`, so an unbounded
        // one put the insert past the column and the whole turn died on a
        // database error — the HTTP route validates `max:255`, and a tool is a
        // second door into the same table.
        $this->gateway->willReply(
            'Writing that up.',
            [['name' => 'write_article', 'arguments' => ['topic' => str_repeat('door cleaning ', 60)]]],
        );

        app(Assistant::class)->reply($this->project, $this->thread(), 'write about doors');

        app(CurrentProject::class)->run($this->project, function (): void {
            $item = ContentItem::query()->roots()->firstOrFail();

            $this->assertLessThanOrEqual(255, mb_strlen((string) $item->target_query));
            $this->assertLessThanOrEqual(255, mb_strlen($item->title));
        });
    }

    #[Test]
    public function an_old_tool_result_is_recalled_rather_than_replayed_whole(): void
    {
        $thread = $this->thread();

        // A tool that returned a great deal, one turn ago.
        app(CurrentProject::class)->run($this->project, static function () use ($thread): void {
            AssistantMessage::query()->create([
                'assistant_thread_id' => $thread->getKey(),
                'role' => AssistantMessage::TOOL,
                'tool_name' => 'read_content_state',
                'tool_arguments' => [],
                'tool_result' => ['titles' => array_fill(0, 200, 'a long article title')],
            ]);
        });

        $this->gateway->willReply('Right.');
        app(Assistant::class)->reply($this->project, $thread, 'what next?');

        $carried = collect($this->gateway->requests[0]->history)
            ->firstWhere(static fn (array $turn): bool => str_contains($turn['content'], '[ran read_content_state]'));

        $this->assertIsArray($carried);
        // Bounded, because a full replay is paid for again on every turn after
        // it: the model needs to remember what it found, not to re-read the
        // whole payload for ever.
        $this->assertLessThan(1000, mb_strlen($carried['content']));
    }

    #[Test]
    public function another_projects_thread_is_never_shown(): void
    {
        $this->gateway->willReply('Noted.');
        app(Assistant::class)->reply($this->project, $this->thread(), 'ours');

        $theirs = Project::factory()->create();

        app(CurrentProject::class)->run($theirs, function () use ($theirs): void {
            $this->gateway->willReply('Also noted.');
            app(Assistant::class)->reply($theirs, AssistantThread::start('theirs'), 'theirs');

            $this->assertSame(
                ['theirs', 'Also noted.'],
                AssistantMessage::query()->oldest()->pluck('body')->all(),
            );
        });
    }

    /**
     * The conversation these turns belong to.
     *
     * Made once and reused, because most of this file is about what happens
     * *within* one conversation — the thread's own behaviour is
     * `ChatScreenTest`'s subject.
     */
    private function thread(): AssistantThread
    {
        return $this->thread ??= AssistantThread::start('A conversation');
    }
}
