<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\Assistant\Assistant;
use App\Models\AssistantMessage;
use App\Models\AssistantThread;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Conversations with the engine: a list, a page each, and a way to start one.
 *
 * **Each one has a URL, and that is the correction.** The first version put a
 * single endless thread on the landing screen — so a conversation could be
 * started and then never found again, and two subjects could not be held apart.
 * Home still has the box, because starting from the screen you land on is the
 * whole point of it; what the box does now is *begin* a conversation and take
 * you to it.
 *
 * **Synchronous, and that is a decision with a cost.** A turn can run several
 * tool calls before the model answers, so the request holds a connection for as
 * long as that takes. Queue-and-poll is the right shape at higher volume and is
 * deliberately not the shape now: this is the one place in the product where a
 * person is waiting *for an answer* rather than for work, and a spinner that
 * resolves into words beats one that resolves into "queued".
 */
class AssistantController extends Controller
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly Assistant $assistant,
    ) {}

    /** Every conversation, newest first. */
    public function index(): Response
    {
        return Inertia::render('chat/index', [
            'threads' => $this->threads(),
        ]);
    }

    /** One conversation, and the box to continue it in. */
    public function show(AssistantThread $thread): Response
    {
        return Inertia::render('chat/show', [
            'thread' => [
                'id' => (string) $thread->getKey(),
                'title' => $thread->title,
            ],
            'turns' => $this->turns($thread),
            'threads' => $this->threads(),
        ]);
    }

    /**
     * Start one, and answer the first thing said in it.
     *
     * The thread is named from that first message before the model is asked
     * anything, so a conversation whose opening turn fails on the wire is still
     * a findable conversation rather than an untitled row.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $project = $this->current->get();

        if ($project === null) {
            return $this->say('error', 'Choose a project first.');
        }

        $thread = AssistantThread::start($validated['message']);

        $this->assistant->reply($project, $thread, $validated['message']);

        return to_route('assistant.show', $thread);
    }

    /** Say the next thing in a conversation that already exists. */
    public function reply(Request $request, AssistantThread $thread): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $project = $this->current->get();

        if ($project === null) {
            return $this->say('error', 'Choose a project first.');
        }

        $this->assistant->reply($project, $thread, $validated['message']);

        return back();
    }

    public function rename(Request $request, AssistantThread $thread): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:120'],
        ]);

        $thread->forceFill(['title' => trim($validated['title'])])->save();

        return back();
    }

    public function destroy(AssistantThread $thread): RedirectResponse
    {
        $thread->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Conversation deleted.']);

        return to_route('assistant.index');
    }

    /**
     * The sidebar's list.
     *
     * Carried on every conversation page rather than fetched separately,
     * because it is how somebody moves between them and a list that arrives on
     * a second request makes the page look like it lost them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function threads(): array
    {
        return AssistantThread::query()
            ->recent()
            ->limit(50)
            ->get()
            ->map(static fn (AssistantThread $thread): array => [
                'id' => (string) $thread->getKey(),
                'title' => $thread->title,
                'last_message_at' => $thread->last_message_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function turns(AssistantThread $thread): array
    {
        // The *last* four hundred, reversed — not the first. Taking the oldest
        // meant a long thread rendered its beginning for ever and every new
        // answer landed outside the window, so replying looked like it did
        // nothing at all.
        return AssistantMessage::query()
            ->where('assistant_thread_id', $thread->getKey())
            ->latest()
            ->limit(400)
            ->get()
            // `reverse()` keeps the original keys, so without `values()` the
            // list serialises with its indices descending and the screen reads
            // the newest turn as the first one.
            ->reverse()
            ->values()
            ->map(static fn (AssistantMessage $message): array => [
                'id' => (string) $message->getKey(),
                'role' => $message->role,
                'body' => $message->body,
                'tool_name' => $message->tool_name,
                'tool_result' => $message->tool_result,
            ])
            ->all();
    }

    private function say(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
