<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Ai\UnmeteredSession;
use App\Content\UnitScore;
use App\ContentStudio\ContentStudioAction;
use App\ContentStudio\ContentStudioOperations;
use App\ContentStudio\PostDirector;
use App\Enums\AssetRole;
use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Enums\PipelineRunStatus;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Pipelines\Definitions\ContentStudioPipeline;
use App\Support\Social\ChannelPayloadSegment;
use App\Support\Social\ChannelPlaybook;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * One post, from its picture to the button that signs it off.
 *
 * The screen the Studio buried. Reviewing a draft used to mean an aside, then a
 * dialog, then a calendar cell, then an inspector, then a card — four levels of
 * *nesting* to reach the thing, and the post's own text rendered inside a 208px
 * scroll box. The reference product's equivalent is four steps *forward*, and
 * the difference is not decoration: a person deciding whether to publish
 * something has to be able to see the whole of it.
 *
 * **Social only.** An article is a different artefact with a different
 * checklist, a different score and a different card that already exists
 * ({@see ContentItemDetailController}). A composer that tried to serve both
 * would be the shared queue's compromise one level down, and the article half
 * of this engine is deliberately untouched by this release.
 *
 * **Nothing publishes here.** Step four's button posts to
 * {@see ApprovalController::approve()}, which is the same gate the queue uses
 * and the only one there is. This is a nicer road to it, not a second door.
 */
class SocialPostController extends Controller
{
    public function __construct(private readonly UnitScore $score) {}

    public function show(Request $request, ContentItem $item): Response
    {
        abort_unless($item->isSocial(), 404);

        $item->load(['everyAsset', 'contentIdea']);

        $channel = ChannelType::tryFrom((string) $item->channel_type);
        $payload = $item->channel_payload ?? [];
        $scored = $this->score->for($item);

        return Inertia::render('social/post', [
            // Editing is a mode reached by navigating, not a panel that
            // appears. The two layouts are genuine inversions of each other —
            // reading a post puts the post in the middle, changing one puts the
            // conversation there — and swapping that on a click would move
            // every block on the screen under the pointer that clicked it. A
            // navigation is allowed to change a page; a toggle is not.
            'editing' => $request->boolean('edit'),
            'post' => [
                'id' => (string) $item->getKey(),
                'title' => $item->title,
                'channel' => $item->channel_type,
                'channel_label' => $channel?->label() ?? (string) $item->channel_type,
                'state' => $item->state->value,
                'state_label' => $item->state->label(),
                // Only a draft may be edited. An approved post is a decision
                // somebody made about a specific text, and letting the text
                // move underneath it would make the approval a statement about
                // nothing. Sending it back is the way to change one, and that
                // already exists.
                'editable' => $item->state === ContentItemState::Draft,
                'format' => is_string($payload['format'] ?? null) ? $payload['format'] : 'post',
                'body' => (string) $item->body_markdown,
                'segments' => $this->segments($payload),
                'caption' => is_string($payload['caption'] ?? null) ? $payload['caption'] : null,
                'slides' => $this->slides($payload),
                'character_limit' => $channel === null
                    ? null
                    : ChannelPlaybook::for($channel)->segmentLimit,
                'scheduled_for' => $item->scheduled_for?->toDateString(),
                'slot_at' => $item->slot_at?->format('Y-m-d\TH:i'),
                'idea' => $item->contentIdea === null ? null : [
                    'id' => (string) $item->contentIdea->getKey(),
                    'title' => $item->contentIdea->title,
                    'thesis' => $item->contentIdea->thesis,
                    'kind_label' => $item->contentIdea->kind->label(),
                ],
                // The two things that would stop an operator approving, on the
                // step where the sentence they refer to is — not three clicks
                // away on a card.
                'guard_findings' => $this->findings($payload),
                'fact_check' => is_array($payload['fact_check'] ?? null)
                    ? $payload['fact_check']
                    : null,
                'publishable' => $scored['publishable'],
                'blocking' => $scored['blocking'],
                'assets' => $this->assets($item),
                'panels' => $this->panels($item),
                'visual_notes' => $this->visualNotes($payload),
                // The conversation so far, so a reviewer returning to a draft
                // can see what they already asked for.
                'edits' => $this->edits($payload),
                // Whether a picture is being drawn for this post *right now*.
                // Without it the screen could only say what it had asked for,
                // never what had happened — so "changed the picture" appeared
                // the instant a redraw was queued, over a picture that had not
                // changed and would not for another half minute.
                'redraw' => $this->redraw($item),
            ],
        ]);
    }

    /**
     * Save the text, and when it goes out.
     *
     * One endpoint for both because they are one form in the operator's head —
     * the composer's steps are a reading order, not a transaction boundary, and
     * two requests would let a person change the caption, lose the connection,
     * and find the schedule saved without it.
     *
     * A published segment is never overwritten. {@see ChannelPayloadSegment}
     * exists because Threads offers no idempotency key and the journal we keep
     * is the only record that a segment already went out; editing the text of
     * one that carries a `published_id` would make the journal describe a post
     * that is not the one on the platform.
     */
    public function update(Request $request, ContentItem $item): RedirectResponse
    {
        abort_unless($item->isSocial(), 404);
        abort_unless(
            $item->state === ContentItemState::Draft,
            409,
            'Only a draft can be edited. Send this one back if it needs to change.',
        );

        $channel = ChannelType::tryFrom((string) $item->channel_type);
        $limit = $channel === null ? 5000 : ChannelPlaybook::for($channel)->segmentLimit;

        $validated = $request->validate([
            'segments' => ['required', 'array', 'min:1', 'max:10'],
            'segments.*' => ['required', 'string', 'min:1', 'max:'.$limit],
            'scheduled_for' => ['required', 'date'],
            'slot_at' => ['nullable', 'date'],
        ], [
            'segments.*.max' => "That is longer than {$channel?->label()} allows ({$limit} characters).",
        ]);

        $payload = $item->channel_payload ?? [];
        $stored = is_array($payload['segments'] ?? null) ? $payload['segments'] : [];
        $written = [];

        foreach (array_values($validated['segments']) as $position => $text) {
            $existing = is_array($stored[$position] ?? null) ? $stored[$position] : [];

            $written[] = [
                ...$existing,
                'text' => ($existing['published_id'] ?? null) === null
                    ? $text
                    : (string) ($existing['text'] ?? $text),
            ];
        }

        $payload['segments'] = $written;

        // Instagram stores the text as a caption as well, and a publisher reads
        // whichever its channel uses. Leaving one of the two behind is how a
        // reviewer approves the text they read and the platform receives the
        // text they replaced.
        if (array_key_exists('caption', $payload)) {
            $payload['caption'] = $written[0]['text'];
        }

        $item->forceFill([
            'body_markdown' => implode("\n\n---\n\n", array_column($written, 'text')),
            'channel_payload' => $payload,
            'scheduled_for' => Carbon::parse($validated['scheduled_for'])->startOfDay(),
            // `?? null` rather than a bare index: a `nullable` rule does not
            // put a missing key into the validated set, so a request that
            // simply omits the slot — which is every save from a step that
            // does not show it — reached an undefined index.
            'slot_at' => ($validated['slot_at'] ?? null) === null
                ? null
                : Carbon::parse((string) $validated['slot_at']),
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Saved.']);

        return back();
    }

    /**
     * Say what is wrong with the post; the engine changes it.
     *
     * The composer used to make a reviewer choose the *control* before they
     * could describe the problem — a caption tab, a picture tab, a note box
     * under the photograph — which meant knowing in advance which half of the
     * post was at fault. "Lead with the checklist and shoot it from above" is
     * one thought and it belongs in one place.
     *
     * So there is one input, and {@see PostDirector} works out whether it is
     * about the words, the picture, or both. The words change here and now,
     * because rewriting a caption is one model call and a person is waiting.
     * The picture goes on the queue through the action that already exists,
     * because drawing one takes tens of seconds and costs money.
     */
    public function edit(
        Request $request,
        ContentItem $item,
        PostDirector $director,
        ContentStudioOperations $operations,
        CurrentProject $current,
    ): JsonResponse {
        abort_unless($item->isSocial(), 404);
        abort_unless(
            $item->state === ContentItemState::Draft,
            409,
            'Only a draft can be edited. Send this one back if it needs to change.',
        );

        $validated = $request->validate([
            'instruction' => ['required', 'string', 'min:2', 'max:4000'],
        ]);

        $channel = ChannelType::tryFrom((string) $item->channel_type);
        $project = $current->get();

        if ($channel === null || $project === null) {
            return response()->json(['message' => 'That draft cannot be edited.'], 422);
        }

        // Only the picture half needs one, and it needs it because the redraw
        // is a pipeline run and a run belongs to a plan. Rewriting the words
        // does not, so a post that somehow has no plan can still be edited
        // rather than being frozen by a requirement its text never had.
        $plan = $item->contentPlan;

        $payload = $item->channel_payload ?? [];
        $visual = is_array($payload['visual'] ?? null) ? $payload['visual'] : [];

        try {
            $revision = $director->revise(
                ChannelPlaybook::for($channel),
                $this->segments($payload),
                array_map(strval(...), $visual),
                $validated['instruction'],
                BrandBrief::activeFor($project),
                app(UnmeteredSession::class),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The editor could not be reached. Try again in a moment.',
            ], 503);
        }

        $redrawing = false;

        if ($revision['segments'] !== null) {
            $payload = $this->writeSegments($payload, $revision['segments']);
        }

        if ($revision['visual'] !== null) {
            $payload['visual'] = $revision['visual'];
            $redrawing = true;
        }

        // The conversation is kept beside the post it changed, for the reason
        // `visual_notes` already existed: a reviewer coming back to a draft has
        // to be able to see what they already asked for, or they ask again.
        $payload['edits'][] = [
            'said' => $validated['instruction'],
            'reply' => $revision['reply'],
            'changed' => array_values(array_filter([
                $revision['segments'] === null ? null : 'text',
                $revision['visual'] === null ? null : 'picture',
            ])),
            // Whether *this* note put a drawing on the queue, as opposed to
            // only revising the brief. Without it the conversation cannot tell
            // "a picture is coming" from "the brief changed and nothing was
            // bought" once the run has finished either way.
            'redraw_queued' => $redrawing && $plan !== null,
            'at' => now()->toIso8601String(),
        ];

        $item->forceFill([
            'channel_payload' => $payload,
            'body_markdown' => $revision['segments'] === null
                ? $item->body_markdown
                : implode("\n\n---\n\n", $revision['segments']),
        ])->save();

        if ($redrawing && $plan !== null) {
            $operations->start($project, $plan, ContentStudioAction::ReviseImage, [
                'content_item_id' => (string) $item->getKey(),
                // Null: the brief has already been revised above, so passing the
                // sentence again would have the art director apply it twice.
                'instruction' => null,
                'variants' => 1,
            ]);
        }

        return response()->json([
            'reply' => $revision['reply'],
            'changed' => $payload['edits'][array_key_last($payload['edits'])]['changed'],
            // The brief was revised either way; this says whether a picture is
            // actually being drawn from it, so the screen does not promise one
            // that is not coming.
            'redrawing' => $redrawing && $plan !== null,
        ]);
    }

    /**
     * The revised text, without touching a segment the platform already has.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private function writeSegments(array $payload, array $segments): array
    {
        $stored = is_array($payload['segments'] ?? null) ? $payload['segments'] : [];
        $written = [];

        foreach ($segments as $position => $text) {
            $existing = is_array($stored[$position] ?? null) ? $stored[$position] : [];

            $written[] = [
                ...$existing,
                'text' => ($existing['published_id'] ?? null) === null
                    ? $text
                    : (string) ($existing['text'] ?? $text),
            ];
        }

        $payload['segments'] = $written;

        if (array_key_exists('caption', $payload)) {
            $payload['caption'] = $written[0]['text'];
        }

        return $payload;
    }

    /**
     * The text as a list, whichever shape the channel stored it in.
     *
     * Instagram writes a caption and the chain channels write segments, and the
     * composer edits one control either way — a reviewer is reading the post,
     * not the storage format.
     *
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function segments(array $payload): array
    {
        $segments = is_array($payload['segments'] ?? null) ? $payload['segments'] : [];
        $text = [];

        foreach ($segments as $segment) {
            if (is_array($segment) && is_string($segment['text'] ?? null)) {
                $text[] = $segment['text'];
            }
        }

        if ($text !== []) {
            return $text;
        }

        return is_string($payload['caption'] ?? null) ? [$payload['caption']] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{heading: string, body: string}>
     */
    private function slides(array $payload): array
    {
        $slides = is_array($payload['slides'] ?? null) ? $payload['slides'] : [];
        $out = [];

        foreach ($slides as $slide) {
            if (is_array($slide)) {
                $out[] = [
                    'heading' => (string) ($slide['heading'] ?? ''),
                    'body' => (string) ($slide['body'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, detail: string}>
     */
    private function findings(array $payload): array
    {
        $findings = is_array($payload['guard_findings'] ?? null) ? $payload['guard_findings'] : [];
        $out = [];

        foreach ($findings as $finding) {
            if (is_array($finding)) {
                $out[] = [
                    'code' => (string) ($finding['code'] ?? ''),
                    'detail' => (string) ($finding['detail'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * What became of the last picture this post asked for.
     *
     * A boolean was not enough, and the gap showed the moment a redraw
     * finished: the badge fell back to "new picture brief" over a picture that
     * had just been drawn and was on screen. Three outcomes, so the
     * conversation can say which one happened rather than only whether
     * something is happening *now*.
     *
     * Null when nothing has ever been queued for this post.
     */
    private function redraw(ContentItem $item): ?string
    {
        $run = PipelineRun::query()
            ->where('pipeline', ContentStudioPipeline::key())
            ->where('input->content_item_id', (string) $item->getKey())
            ->latest()
            ->first();

        return match ($run?->status) {
            null => null,
            PipelineRunStatus::Pending, PipelineRunStatus::Running => 'running',
            PipelineRunStatus::Failed => 'failed',
            default => 'done',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{said: string, reply: string, changed: list<string>, redraw_queued: bool|null, at: string}>
     */
    private function edits(array $payload): array
    {
        $edits = is_array($payload['edits'] ?? null) ? $payload['edits'] : [];
        $out = [];

        foreach ($edits as $edit) {
            if (! is_array($edit) || ! is_string($edit['said'] ?? null)) {
                continue;
            }

            $changed = is_array($edit['changed'] ?? null) ? $edit['changed'] : [];

            $out[] = [
                'said' => $edit['said'],
                'reply' => (string) ($edit['reply'] ?? ''),
                'changed' => array_values(array_map(strval(...), $changed)),
                // Null, not false, when the key is absent — which is every
                // note written before this field existed. Absent means
                // *unknown*, and reading it as "nothing was queued" made the
                // conversation deny a redraw that had demonstrably happened:
                // the badge claimed the brief had only changed, and the picture
                // that note produced was hidden. Unknown falls back to the
                // run's own outcome, which is recoverable; false does not.
                'redraw_queued' => array_key_exists('redraw_queued', $edit)
                    ? (bool) $edit['redraw_queued']
                    : null,
                'at' => (string) ($edit['at'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function visualNotes(array $payload): array
    {
        $notes = is_array($payload['visual_notes'] ?? null) ? $payload['visual_notes'] : [];
        $out = [];

        foreach ($notes as $note) {
            if (is_array($note) && is_string($note['said'] ?? null)) {
                $out[] = $note['said'];
            }
        }

        return $out;
    }

    /**
     * A carousel's drawn slides, in the order they publish.
     *
     * Separate from {@see assets()} on purpose, and the separation is the whole
     * reason this method exists rather than the filter there simply being
     * loosened. The strip below the preview asks "which of these should be the
     * picture", and a panel is not a candidate for that — offering slide four as
     * a possible cover is an invitation to break the sequence. So `assets()`
     * rejects them, correctly.
     *
     * What was missing is anywhere else for them to go. `CarouselPanels` draws
     * them, {@see WebhookPayload} publishes them alongside the hero, and this
     * screen — the one place a person signs the post off — showed a single
     * photograph and the slide *text*. An operator was approving six pictures
     * they could not see, on the only screen whose job is to look at the post
     * before it goes out.
     *
     * Superseded rows are excluded here, unlike in `assets()`: a redrawn panel
     * is not an alternative anybody chooses between, it is simply the old
     * slide two.
     *
     * @return list<array<string, mixed>>
     */
    private function panels(ContentItem $item): array
    {
        /** @var list<array<string, mixed>> $panels */
        $panels = $item->everyAsset
            ->filter(static fn ($asset): bool => $asset->role === AssetRole::Inline
                && $asset->superseded_at === null)
            // By anchor, which `CarouselPanels` zero-pads precisely so that a
            // ten-slide carousel does not publish in the order 1, 10, 2.
            ->sortBy('anchor')
            ->map(static fn ($asset): array => [
                'id' => (string) $asset->getKey(),
                'url' => $asset->url(),
                'alt' => $asset->alt,
                'width' => $asset->width,
                'height' => $asset->height,
            ])
            ->values()
            ->all();

        return $panels;
    }

    /**
     * Every picture the draft has, the chosen one first.
     *
     * `everyAsset` rather than `assets`: choosing a candidate retires the one
     * it replaced, and the filtered relation would hide it — which would make
     * "put back the one you rejected" unreachable, the very thing retiring
     * instead of deleting exists to allow.
     *
     * Panels are rejected because they are not candidates; {@see panels()}
     * shows them as the sequence they are.
     *
     * @return list<array<string, mixed>>
     */
    private function assets(ContentItem $item): array
    {
        // `values()` is what makes this a list at runtime — and the list matters
        // rather than being pedantry, because a non-list serialises as a JSON
        // object and the screen iterates an array.
        /** @var list<array<string, mixed>> $assets */
        $assets = $item->everyAsset
            ->reject(static fn ($asset): bool => $asset->role === AssetRole::Inline)
            ->sortBy(static fn ($asset): array => [
                $asset->isHero() ? 0 : 1,
                $asset->superseded_at === null ? 0 : 1,
                (string) $asset->getKey(),
            ])
            ->map(static fn ($asset): array => [
                'id' => (string) $asset->getKey(),
                'url' => $asset->url(),
                'alt' => $asset->alt,
                'width' => $asset->width,
                'height' => $asset->height,
                'chosen' => $asset->isHero(),
                'retired' => $asset->superseded_at !== null,
                'source' => $asset->source->value,
                // When it arrived, which is how the conversation works out
                // which pictures a given note produced — an edit and the
                // assets drawn after it.
                'created_at' => $asset->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return $assets;
    }
}
