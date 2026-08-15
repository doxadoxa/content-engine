<?php

declare(strict_types=1);

namespace Tests\Feature\Social;

use App\Ai\Contracts\ModelGateway;
use App\Ai\FakeModelGateway;
use App\Ai\ModelRequest;
use App\Enums\ContentItemState;
use App\Enums\ContentItemType;
use App\Enums\PipelineRunStatus;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The composer: one post, four steps, and the same approval gate as before.
 *
 * Most of what is pinned here is what the screen must *refuse* — an article, a
 * post somebody already approved, text past the platform's limit, and above all
 * a segment the platform already has.
 */
final class SocialComposerTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-14 10:00:00');

        $this->operator = User::factory()->create();
        $this->project = Project::factory()->create();
        $this->project->users()->attach($this->operator);
        $this->actingAs($this->operator);
        $this->withSession(['project_id' => $this->project->getKey()]);
    }

    #[Test]
    public function a_draft_opens_with_its_text_its_objections_and_its_pictures(): void
    {
        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'An entrance is a transition system.']],
            'guard_findings' => [['code' => 'bare_link', 'detail' => 'A bare link with no framing.']],
            'fact_check' => ['passed' => false, 'findings' => ['97% is not in your facts.']],
            'visual_notes' => [['said' => 'too clean', 'at' => '2026-08-14T09:00:00+00:00']],
        ]);

        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('social/post')
                ->where('post.channel', 'threads')
                ->where('post.editable', true)
                ->where('post.segments.0', 'An entrance is a transition system.')
                // The two things that would stop somebody approving, carried to
                // the step where the sentence they refer to is.
                ->where('post.guard_findings.0.detail', 'A bare link with no framing.')
                ->where('post.fact_check.passed', false)
                ->where('post.visual_notes.0', 'too clean')
                // The channel's own ceiling, so the counter is the platform's
                // rather than a number the screen invented.
                ->whereNot('post.character_limit', null)
            );
    }

    /**
     * Editing is a mode reached by navigating, not a panel that appears.
     *
     * The two layouts are inversions of each other — reading a post puts the
     * post in the middle, changing one puts the conversation there — and doing
     * that on a click would move every block on the screen under the pointer
     * that clicked it.
     */
    #[Test]
    public function editing_is_a_separate_mode_of_the_same_screen(): void
    {
        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Readable.']],
        ]);

        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('editing', false)
                ->etc()
            );

        $this->get("/social/posts/{$item->getKey()}?edit=1")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('social/post')
                ->where('editing', true)
                ->etc()
            );
    }

    #[Test]
    public function the_text_and_the_schedule_are_saved_together(): void
    {
        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Before.']],
        ]);

        $this->patch("/social/posts/{$item->getKey()}", [
            'segments' => ['After.'],
            'scheduled_for' => '2026-08-20',
            'slot_at' => '2026-08-20T09:20',
        ])->assertRedirect();

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $fresh = ContentItem::query()->whereKey($item->getKey())->firstOrFail();

            $this->assertSame('After.', $fresh->channel_payload['segments'][0]['text']);
            // The body too: a publisher reads the payload and a human reads the
            // body, and letting them disagree is how somebody approves one text
            // and the platform receives another.
            $this->assertSame('After.', $fresh->body_markdown);
            $this->assertSame('2026-08-20', $fresh->scheduled_for->toDateString());
            $this->assertSame('09:20', $fresh->slot_at->format('H:i'));
        });
    }

    /**
     * The one edit that must never be applied.
     *
     * Threads offers no idempotency key, so the per-segment journal is the only
     * record that a segment already went out. Rewriting the text of a segment
     * carrying a `published_id` would leave the journal describing a post that
     * is not the one on the platform.
     */
    #[Test]
    public function a_segment_the_platform_already_has_is_never_rewritten(): void
    {
        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'thread',
            'segments' => [
                ['text' => 'Already out.', 'published_id' => 'th_1', 'container_id' => 'c_1'],
                ['text' => 'Still ours.'],
            ],
        ]);

        $this->patch("/social/posts/{$item->getKey()}", [
            'segments' => ['Rewritten!', 'Edited too.'],
            'scheduled_for' => '2026-08-20',
        ])->assertRedirect();

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $segments = ContentItem::query()->whereKey($item->getKey())
                ->firstOrFail()->channel_payload['segments'];

            $this->assertSame('Already out.', $segments[0]['text']);
            $this->assertSame('th_1', $segments[0]['published_id']);
            // The journal's other half survives the edit as well.
            $this->assertSame('c_1', $segments[0]['container_id']);
            $this->assertSame('Edited too.', $segments[1]['text']);
        });
    }

    #[Test]
    public function an_instagram_caption_is_kept_in_step_with_its_segments(): void
    {
        $item = $this->draft(
            state: ContentItemState::Draft,
            channel: 'instagram',
            payload: [
                'format' => 'carousel',
                'caption' => 'Before.',
                'segments' => [['text' => 'Before.']],
                'slides' => [['heading' => 'One', 'body' => 'Do the thing.']],
            ],
        );

        $this->patch("/social/posts/{$item->getKey()}", [
            'segments' => ['After.'],
            'scheduled_for' => '2026-08-20',
        ])->assertRedirect();

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $payload = ContentItem::query()->whereKey($item->getKey())
                ->firstOrFail()->channel_payload;

            $this->assertSame('After.', $payload['caption']);
            $this->assertSame('After.', $payload['segments'][0]['text']);
            // Untouched: the slides are the carousel's sequence, not the
            // caption the composer edits.
            $this->assertSame('One', $payload['slides'][0]['heading']);
        });
    }

    #[Test]
    public function an_approved_post_is_read_only(): void
    {
        $item = $this->draft(state: ContentItemState::Approved, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Signed off.']],
        ]);

        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.editable', false)
            );

        $this->patch("/social/posts/{$item->getKey()}", [
            'segments' => ['Changed after the fact.'],
            'scheduled_for' => '2026-08-20',
        ])->assertStatus(409);

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $this->assertSame(
                'Signed off.',
                ContentItem::query()->whereKey($item->getKey())
                    ->firstOrFail()->channel_payload['segments'][0]['text'],
            );
        });
    }

    #[Test]
    public function text_past_the_platforms_limit_is_refused(): void
    {
        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Short.']],
        ]);

        $this->patch("/social/posts/{$item->getKey()}", [
            'segments' => [str_repeat('a', 10_000)],
            'scheduled_for' => '2026-08-20',
        ])->assertSessionHasErrors('segments.0');
    }

    /**
     * One sentence, and the engine decides which half of the post it is about.
     *
     * This is the whole reason the four tabs went: a reviewer had to pick the
     * caption control or the picture control before they could say what was
     * wrong, which means classifying their own complaint first.
     */
    #[Test]
    public function a_note_about_the_words_rewrites_the_words_and_draws_nothing(): void
    {
        $this->director(touches: 'text', segments: ['Shorter, and it leads with the checklist.']);

        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'A long opening that buries the checklist somewhere near the end.']],
            'visual' => ['subject' => 'a hand on a handle', 'light' => 'morning'],
        ]);

        $this->postJson("/social/posts/{$item->getKey()}/edit", [
            'instruction' => 'Lead with the checklist and make it shorter.',
        ])
            ->assertOk()
            ->assertJsonPath('changed', ['text'])
            ->assertJsonPath('redrawing', false);

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $fresh = ContentItem::query()->whereKey($item->getKey())->firstOrFail();

            $this->assertSame(
                'Shorter, and it leads with the checklist.',
                $fresh->channel_payload['segments'][0]['text'],
            );
            $this->assertSame($fresh->channel_payload['segments'][0]['text'], $fresh->body_markdown);
            // The picture was not mentioned, so nothing was bought.
            $this->assertSame(0, PipelineRun::query()->where('input->action', 'revise_image')->count());

            // And the conversation is kept beside the post it changed.
            $this->assertSame(
                'Lead with the checklist and make it shorter.',
                $fresh->channel_payload['edits'][0]['said'],
            );
        });
    }

    #[Test]
    public function a_note_about_the_picture_revises_the_brief_and_queues_a_redraw(): void
    {
        $this->director(touches: 'picture');

        $item = $this->draft(
            state: ContentItemState::Draft,
            payload: [
                'format' => 'post',
                'segments' => [['text' => 'Unchanged words.']],
                'visual' => ['subject' => 'a hand on a handle', 'light' => 'morning'],
            ],
            withPlan: true,
        );

        $this->postJson("/social/posts/{$item->getKey()}/edit", [
            'instruction' => 'Too clean — show the actual residue.',
        ])
            ->assertOk()
            ->assertJsonPath('changed', ['picture'])
            ->assertJsonPath('redrawing', true);

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $fresh = ContentItem::query()->whereKey($item->getKey())->firstOrFail();

            // The words are untouched, and the six fields were revised rather
            // than the sentence being appended to a prompt.
            $this->assertSame('Unchanged words.', $fresh->channel_payload['segments'][0]['text']);
            $this->assertSame('a hand on a handle, with visible residue', $fresh->channel_payload['visual']['subject']);

            $run = PipelineRun::query()->where('input->action', 'revise_image')->firstOrFail();
            // Null instruction: the brief is already revised, so passing the
            // sentence again would apply it twice.
            $this->assertNull($run->input['instruction']);
        });
    }

    /**
     * A rewrite past the platform's ceiling is refused rather than stored.
     *
     * Storing it would put a finished-looking draft in the queue that the
     * publisher will later refuse — a failure discovered at the worst moment.
     */
    #[Test]
    public function a_rewrite_over_the_channel_limit_is_not_applied(): void
    {
        $this->director(touches: 'text', segments: [str_repeat('a', 9_000)]);

        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Short and publishable.']],
        ]);

        $this->postJson("/social/posts/{$item->getKey()}/edit", [
            'instruction' => 'Say much more.',
        ])->assertOk()->assertJsonPath('changed', []);

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $this->assertSame(
                'Short and publishable.',
                ContentItem::query()->whereKey($item->getKey())
                    ->firstOrFail()->channel_payload['segments'][0]['text'],
            );
        });
    }

    #[Test]
    public function a_segment_the_platform_already_has_survives_an_edit(): void
    {
        $this->director(touches: 'text', segments: ['Rewritten first.', 'Rewritten second.']);

        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'thread',
            'segments' => [
                ['text' => 'Already out.', 'published_id' => 'th_1'],
                ['text' => 'Still ours.'],
            ],
        ]);

        $this->postJson("/social/posts/{$item->getKey()}/edit", [
            'instruction' => 'Tighten both parts.',
        ])->assertOk();

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            $segments = ContentItem::query()->whereKey($item->getKey())
                ->firstOrFail()->channel_payload['segments'];

            $this->assertSame('Already out.', $segments[0]['text']);
            $this->assertSame('Rewritten second.', $segments[1]['text']);
        });
    }

    /**
     * "Changed the picture" was wrong twice over.
     *
     * The picture had not changed, and it was not going to for another half
     * minute — a redraw is a queued run that costs money and can still fail.
     * What changes immediately is the *brief*, so the screen has to be able to
     * tell the two apart, which means knowing whether a run is actually in
     * flight rather than merely having been asked for.
     */
    #[Test]
    public function the_post_says_whether_a_picture_is_actually_being_drawn(): void
    {
        $this->director(touches: 'picture');

        $item = $this->draft(
            state: ContentItemState::Draft,
            payload: [
                'format' => 'post',
                'segments' => [['text' => 'Unchanged words.']],
                'visual' => ['subject' => 'a hand on a handle', 'light' => 'morning'],
            ],
            withPlan: true,
        );

        // Nothing queued yet: the brief is whatever it is, and no picture is
        // on its way.
        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.redraw', null)
                ->etc()
            );

        $this->postJson("/social/posts/{$item->getKey()}/edit", [
            'instruction' => 'Too clean — show the actual residue.',
        ])->assertOk()->assertJsonPath('redrawing', true);

        // The queue is synchronous here, so the run this started has already
        // finished — which is itself the point: the flag is about a run that
        // is *in flight*, not about one having been asked for. Held open by
        // hand so both readings can be pinned.
        $run = app(CurrentProject::class)->run(
            $this->project,
            static fn (): PipelineRun => PipelineRun::query()
                ->where('input->action', 'revise_image')
                ->latest()
                ->firstOrFail(),
        );

        $run->forceFill(['status' => PipelineRunStatus::Running])->save();

        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.redraw', 'running')
                ->etc()
            );

        $run->forceFill(['status' => PipelineRunStatus::Completed])->save();

        // Finished, not idle. The badge fell back to "new picture brief" here
        // once — over a picture that had just been drawn and was on screen.
        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.redraw', 'done')
                ->etc()
            );

        $run->forceFill(['status' => PipelineRunStatus::Failed])->save();

        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.redraw', 'failed')
                ->etc()
            );
    }

    /**
     * A note records whether it actually bought a drawing.
     *
     * "The brief changed" and "a picture is coming" are different outcomes, and
     * once a run has finished they are indistinguishable without this — which
     * is how a badge ended up describing the wrong one.
     */
    /**
     * A note written before the flag existed is unknown, not "nothing queued".
     *
     * Read as false, it made the conversation deny a redraw that had already
     * happened — the badge claimed only the brief had changed, and the picture
     * that note produced was hidden from the message that produced it.
     */
    #[Test]
    public function a_note_from_before_the_flag_existed_is_unknown_rather_than_false(): void
    {
        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Words.']],
            'edits' => [[
                'said' => 'Too clean.',
                'reply' => 'Simplified the brief.',
                'changed' => ['picture'],
                'at' => '2026-08-14T17:40:57+00:00',
            ]],
        ]);

        $this->get("/social/posts/{$item->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.edits.0.redraw_queued', null)
                ->etc()
            );
    }

    #[Test]
    public function a_note_records_whether_it_queued_a_drawing(): void
    {
        $this->director(touches: 'picture');

        $queued = $this->draft(
            state: ContentItemState::Draft,
            payload: [
                'format' => 'post',
                'segments' => [['text' => 'Words.']],
                'visual' => ['subject' => 'a hand on a handle'],
            ],
            withPlan: true,
        );

        $this->postJson("/social/posts/{$queued->getKey()}/edit", [
            'instruction' => 'Show the residue.',
        ])->assertOk();

        $this->get("/social/posts/{$queued->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.edits.0.redraw_queued', true)
                ->etc()
            );

        // The same note on a post with nowhere to hang a run revises the brief
        // and says so, rather than promising a picture nobody is drawing.
        $planless = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Words.']],
            'visual' => ['subject' => 'a hand on a handle'],
        ]);

        $this->postJson("/social/posts/{$planless->getKey()}/edit", [
            'instruction' => 'Show the residue.',
        ])->assertOk();

        $this->get("/social/posts/{$planless->getKey()}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('post.edits.0.redraw_queued', false)
                ->etc()
            );
    }

    /**
     * A post with no plan still edits, and says no picture is coming.
     *
     * The redraw is a pipeline run and a run belongs to a plan, so the text
     * half must not inherit that requirement — and the screen must not promise
     * a picture that was never queued.
     */
    #[Test]
    public function a_picture_note_on_a_planless_post_revises_the_brief_and_promises_nothing(): void
    {
        $this->director(touches: 'picture');

        $item = $this->draft(state: ContentItemState::Draft, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Unchanged words.']],
            'visual' => ['subject' => 'a hand on a handle', 'light' => 'morning'],
        ]);

        $this->postJson("/social/posts/{$item->getKey()}/edit", [
            'instruction' => 'Show the residue.',
        ])->assertOk()->assertJsonPath('redrawing', false);

        app(CurrentProject::class)->run($this->project, function () use ($item): void {
            // The brief was still revised — that half costs nothing and is the
            // half a later redraw would read from.
            $this->assertSame(
                'a hand on a handle, with visible residue',
                ContentItem::query()->whereKey($item->getKey())
                    ->firstOrFail()->channel_payload['visual']['subject'],
            );
        });
    }

    #[Test]
    public function an_approved_post_cannot_be_edited_by_conversation_either(): void
    {
        $this->director(touches: 'text', segments: ['Changed after the fact.']);

        $item = $this->draft(state: ContentItemState::Approved, payload: [
            'format' => 'post',
            'segments' => [['text' => 'Signed off.']],
        ]);

        $this->postJson("/social/posts/{$item->getKey()}/edit", [
            'instruction' => 'Change it anyway.',
        ])->assertStatus(409);
    }

    #[Test]
    public function an_article_is_not_a_post_and_has_no_composer(): void
    {
        $article = app(CurrentProject::class)->run(
            $this->project,
            static fn (): ContentItem => ContentItem::factory()->create([
                'type' => ContentItemType::HowTo,
                'channel_type' => null,
                'state' => ContentItemState::Draft,
            ]),
        );

        $this->get("/social/posts/{$article->getKey()}")->assertNotFound();
        $this->patch("/social/posts/{$article->getKey()}", [
            'segments' => ['Nope.'],
            'scheduled_for' => '2026-08-20',
        ])->assertNotFound();
    }

    #[Test]
    public function another_projects_post_is_not_addressable(): void
    {
        $other = Project::factory()->create();

        $item = app(CurrentProject::class)->run(
            $other,
            static fn (): ContentItem => ContentItem::factory()->create([
                'type' => ContentItemType::SocialPost,
                'channel_type' => 'threads',
                'state' => ContentItemState::Draft,
            ]),
        );

        $this->get("/social/posts/{$item->getKey()}")->assertNotFound();
    }

    /**
     * A gateway that answers one revision.
     *
     * @param  list<string>|null  $segments
     */
    private function director(string $touches, ?array $segments = null): void
    {
        $this->app->instance(
            ModelGateway::class,
            (new FakeModelGateway)->willAnswerUsing(
                static function (ModelRequest $request) use ($touches, $segments): string {
                    // The art director's own call, which PostDirector delegates
                    // to unchanged rather than reimplementing.
                    if (str_contains($request->instructions, 'art director')) {
                        return (string) json_encode([
                            'subject' => 'a hand on a handle, with visible residue',
                            'composition' => 'close crop',
                            'action' => 'wiping',
                            'location' => 'an entryway',
                            'style' => 'documentary',
                            'light' => 'morning',
                        ]);
                    }

                    return (string) json_encode([
                        'touches' => $touches,
                        'segments' => $segments ?? [],
                        'reply' => 'Done.',
                    ]);
                },
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  bool  $withPlan  a redraw is a pipeline run, and a run needs one
     */
    private function draft(
        ContentItemState $state,
        array $payload,
        string $channel = 'threads',
        bool $withPlan = false,
    ): ContentItem {
        return app(CurrentProject::class)->run(
            $this->project,
            static fn (): ContentItem => ContentItem::factory()->create([
                'type' => ContentItemType::SocialPost,
                'channel_type' => $channel,
                'state' => $state,
                'channel_payload' => $payload,
                'body_markdown' => 'Before.',
                'scheduled_for' => '2026-08-14',
                'content_plan_id' => $withPlan
                    ? ContentPlan::factory()->forMonth('2026-08-01')->create()->getKey()
                    : null,
            ]),
        );
    }
}
