<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use App\Ai\Contracts\ConversationGateway;
use App\Ai\FakeConversationGateway;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Conversations as places rather than as a panel.
 *
 * The feature shipped first as one endless thread on the landing screen, which
 * made a conversation something you had once and could not return to, and made
 * two subjects impossible to hold apart. Everything here is about the
 * correction: a name, an address, and a list.
 */
final class ChatScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    private FakeConversationGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->users()->attach($this->operator);

        $this->actingAs($this->operator);
        $this->withSession(['project_id' => $this->project->getKey()]);

        $gateway = app(ConversationGateway::class);
        $this->assertInstanceOf(FakeConversationGateway::class, $gateway);
        $this->gateway = $gateway;
    }

    #[Test]
    public function asking_from_home_starts_a_named_conversation_and_lands_on_it(): void
    {
        $this->gateway->willReply('Which of those two matters more this month?');

        $response = $this->post('/chat', [
            'message' => 'how do we get found for door cleaning in Lisbon?',
        ]);

        $thread = AssistantThread::query()->firstOrFail();

        // Named from the first thing said in it, so the list is readable
        // without opening anything.
        $this->assertSame(
            'how do we get found for door cleaning in Lisbon?',
            $thread->title,
        );

        $response->assertRedirect(route('assistant.show', $thread));
    }

    #[Test]
    public function a_long_opening_message_still_makes_a_readable_name(): void
    {
        $this->gateway->willReply('Noted.');

        $this->post('/chat', [
            'message' => 'I want to work out why our Portuguese visibility is zero '
                .'when the business is in Lisbon and most of our customers speak it',
        ])->assertRedirect();

        $title = (string) AssistantThread::query()->firstOrFail()->title;

        $this->assertLessThanOrEqual(60, mb_strlen($title));
        // Cut at a word: a name ending mid-syllable reads as a broken product
        // rather than as a summary.
        $this->assertSame($title, rtrim($title));
        $this->assertStringStartsWith('I want to work out why', $title);
    }

    #[Test]
    public function a_conversation_shows_its_own_turns_and_no_others(): void
    {
        $this->gateway->willReply('First answer.');
        $this->post('/chat', ['message' => 'first subject'])->assertRedirect();

        $this->gateway->willReply('Second answer.');
        $this->post('/chat', ['message' => 'second subject'])->assertRedirect();

        $first = AssistantThread::query()->where('title', 'first subject')->firstOrFail();

        $this->get(route('assistant.show', $first))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('chat/show')
                ->where('thread.title', 'first subject')
                ->has('turns', 2)
                ->where('turns.0.body', 'first subject')
                ->where('turns.1.body', 'First answer.')
                // Both are offered, because moving between them is the whole
                // reason they have addresses.
                ->has('threads', 2)
            );
    }

    #[Test]
    public function replying_continues_the_same_conversation(): void
    {
        $this->gateway->willReply('Which channels?');
        $this->post('/chat', ['message' => 'door cleaning'])->assertRedirect();

        $thread = AssistantThread::query()->firstOrFail();

        $this->gateway->willReply('Threads it is.');
        $this->post(route('assistant.reply', $thread), ['message' => 'threads'])
            ->assertRedirect();

        $this->assertSame(1, AssistantThread::query()->count());
        $this->assertSame(
            4,
            AssistantMessage::query()->where('assistant_thread_id', $thread->getKey())->count(),
        );
    }

    #[Test]
    public function the_list_is_ordered_by_when_something_was_last_said(): void
    {
        $this->gateway->willReply('One.');
        $this->post('/chat', ['message' => 'older subject'])->assertRedirect();

        $this->gateway->willReply('Two.');
        $this->post('/chat', ['message' => 'newer subject'])->assertRedirect();

        $older = AssistantThread::query()->where('title', 'older subject')->firstOrFail();

        // Saying something in the old one brings it back to the top; a rename
        // deliberately would not, which is why the column is not `updated_at`.
        $this->travelTo(now()->addMinute());
        $this->gateway->willReply('Three.');
        $this->post(route('assistant.reply', $older), ['message' => 'one more thing'])
            ->assertRedirect();

        $this->get('/chat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('chat/index')
                ->where('threads.0.title', 'older subject')
                ->where('threads.1.title', 'newer subject')
            );
    }

    #[Test]
    public function a_conversation_can_be_renamed_without_reordering_the_list(): void
    {
        $this->gateway->willReply('One.');
        $this->post('/chat', ['message' => 'older subject'])->assertRedirect();

        $this->gateway->willReply('Two.');
        $this->post('/chat', ['message' => 'newer subject'])->assertRedirect();

        $older = AssistantThread::query()->where('title', 'older subject')->firstOrFail();

        $this->patch(route('assistant.rename', $older), ['title' => 'The door cleaning question'])
            ->assertRedirect();

        $this->get('/chat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('threads.0.title', 'newer subject')
                ->where('threads.1.title', 'The door cleaning question')
            );
    }

    #[Test]
    public function deleting_a_conversation_takes_its_turns_with_it(): void
    {
        $this->gateway->willReply('One.');
        $this->post('/chat', ['message' => 'a subject'])->assertRedirect();

        $thread = AssistantThread::query()->firstOrFail();

        $this->delete(route('assistant.destroy', $thread))
            ->assertRedirect(route('assistant.index'));

        $this->assertSame(0, AssistantThread::query()->count());
        $this->assertSame(0, AssistantMessage::query()->count());
    }

    #[Test]
    public function a_long_conversation_shows_its_newest_turns(): void
    {
        $this->gateway->willReply('One.');
        $this->post('/chat', ['message' => 'the subject'])->assertRedirect();

        $thread = AssistantThread::query()->firstOrFail();

        // Past the window the screen carries. Taking the *oldest* rows meant a
        // long thread rendered its beginning for ever and every new answer
        // landed outside the window — so replying looked like it did nothing.
        app(CurrentProject::class)->run($this->project, static function () use ($thread): void {
            for ($i = 0; $i < 405; $i++) {
                AssistantMessage::query()->create([
                    'assistant_thread_id' => $thread->getKey(),
                    'role' => AssistantMessage::USER,
                    'body' => "filler {$i}",
                ]);
            }

            AssistantMessage::query()->create([
                'assistant_thread_id' => $thread->getKey(),
                'role' => AssistantMessage::ASSISTANT,
                'body' => 'the newest thing said',
            ]);
        });

        $this->get(route('assistant.show', $thread))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $turns = $page->toArray()['props']['turns'];

                $this->assertIsArray($turns);
                $this->assertSame(
                    'the newest thing said',
                    $turns[array_key_last($turns)]['body'],
                );
            });
    }

    #[Test]
    public function refusing_to_plan_twice_says_so_where_a_person_can_see_it(): void
    {
        // Through `Inertia::flash`, which is what this app's toast reads. A
        // plain session flash is shared by nothing and rendered by nothing, so
        // pressing the button while a run was in flight looked exactly like
        // pressing a dead one.
        $this->post('/content/plan')->assertRedirect();
        $this->from('/home')->post('/content/plan')->assertRedirect();

        // Asserted on the next render, the way `SharedPropsTest` does: the
        // toast hook listens for Inertia's own flash event, so a value under a
        // different key never reaches a screen.
        $this->get('/home')
            ->assertOk()
            ->assertSee('already being planned', escape: false);
    }

    #[Test]
    public function another_projects_conversation_is_not_reachable(): void
    {
        $theirs = Project::factory()->create();

        $hidden = app(CurrentProject::class)->run(
            $theirs,
            static fn (): AssistantThread => AssistantThread::start('their subject'),
        );

        // Scoped by the model rather than by this controller, which is the
        // point of `BelongsToProject`: the query somebody forgets to scope is
        // the one that leaks.
        $this->get(route('assistant.show', $hidden))->assertNotFound();

        $this->get('/chat')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('threads', 0));
    }

    #[Test]
    public function home_offers_the_way_back_into_recent_conversations(): void
    {
        $this->gateway->willReply('Noted.');
        $this->post('/chat', ['message' => 'the door cleaning question'])->assertRedirect();

        $this->get('/home')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('chats', 1)
                ->where('chats.0.title', 'the door cleaning question')
                ->etc()
            );
    }
}
