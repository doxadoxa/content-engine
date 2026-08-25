<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\ContentStudio\ContentStudioAction;
use App\ContentStudio\ContentStudioAssistant;
use App\ContentStudio\ContentStudioConflict;
use App\ContentStudio\ContentStudioException;
use App\ContentStudio\ContentStudioOperations;
use App\Enums\AssetRole;
use App\Enums\ChannelType;
use App\Enums\ContentFormat;
use App\Enums\PipelineRunStatus;
use App\Enums\PostKind;
use App\Media\UploadedPicture;
use App\Models\Asset;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Models\Signal;
use App\Pipelines\Definitions\ContentStudioPipeline;
use App\Social\GoalSummary;
use App\Support\Social\ChannelPlaybook;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/** The hybrid assistant + artifact workspace for one project-month. */
class ContentStudioController extends Controller
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly ContentStudioOperations $operations,
    ) {}

    public function index(Request $request): Response
    {
        $project = $this->project();
        $month = $this->month((string) $request->query('month', now()->format('Y-m')));
        $plan = ContentPlan::query()->whereDate('month', $month)->first();

        return Inertia::render('social/plan', [
            'month' => $month->format('Y-m'),
            'label' => $month->translatedFormat('F Y'),
            'previous' => $month->copy()->subMonth()->format('Y-m'),
            'next' => $month->copy()->addMonth()->format('Y-m'),
            'source' => [
                'website_url' => $project->website_url,
                'site_name' => (string) ($project->site_analysis['name'] ?? $project->name),
                'site_description' => (string) ($project->site_analysis['description'] ?? ''),
                'has_brief' => $project->brandBriefs()->where('is_active', true)->exists(),
                'site_articles' => $project->sitePages()->articles()->count(),
            ],
            'plan' => $plan === null ? null : $this->planProps($plan),
            // Beside the plan rather than inside it. A goal survives every
            // proposal made against it, so a screen that read it out of
            // `plan.goal` would show it disappearing for the seconds a
            // refinement is in flight.
            'goal' => GoalSummary::forMonth($month),
            'kpis' => GoalSummary::kpis(),
            'operation' => $plan === null ? null : $this->operationProps($this->latestOperation($plan)),
        ]);
    }

    public function propose(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
        ]);

        $plan = ContentPlan::query()->firstOrCreate([
            'month' => $this->month($validated['month']),
        ]);

        if ($plan->assistant_version > 0) {
            return response()->json([
                'plan' => $this->planProps($plan),
                'operation' => null,
            ]);
        }

        $run = $this->operations->start($this->project(), $plan, ContentStudioAction::Proposal);

        return response()->json([
            'plan' => $this->planProps($plan->refresh()),
            'operation' => $this->operationProps($run->refresh()),
        ], 202);
    }

    public function refine(
        Request $request,
        ContentPlan $plan,
    ): JsonResponse {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'version' => ['required', 'integer', 'min:1'],
        ]);

        if ($plan->assistant_version !== (int) $validated['version']) {
            return response()->json([
                'message' => 'This proposal changed while you were editing it. Reload and refine the latest version.',
            ], 409);
        }

        $run = $this->operations->start($this->project(), $plan, ContentStudioAction::Refine, [
            'expected_version' => (int) $validated['version'],
            'message' => $validated['message'],
        ]);

        return response()->json([
            'plan' => $this->planProps($plan->refresh()),
            'operation' => $this->operationProps($run->refresh()),
        ], 202);
    }

    public function accept(
        Request $request,
        ContentPlan $plan,
        ContentStudioAssistant $assistant,
    ): JsonResponse {
        $validated = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $updated = $assistant->accept($plan, (int) $validated['version']);

            // The goal comes back too, because accepting confirmed it. Without
            // it the screen would show an accepted plan above a goal still
            // labelled "not approved" until the next full page load.
            return response()->json([
                'plan' => $this->planProps($updated),
                'goal' => GoalSummary::forMonth($updated->month),
            ]);
        } catch (ContentStudioConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ContentStudioException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The proposal could not be accepted right now.'], 500);
        }
    }

    /**
     * Draw this draft another picture, optionally after being told what is wrong.
     *
     * Queued like every other model action here. The response carries the
     * operation so the screen can show that something is being drawn rather
     * than appearing to do nothing for forty seconds.
     */
    public function reviseImage(Request $request, ContentItem $item): JsonResponse
    {
        $validated = $request->validate([
            'instruction' => ['nullable', 'string', 'max:2000'],
            'variants' => ['nullable', 'integer', 'min:1', 'max:3'],
        ]);

        $plan = $item->contentPlan;

        if ($plan === null) {
            return response()->json(['message' => 'That draft does not belong to a plan.'], 422);
        }

        $run = $this->operations->start($this->project(), $plan, ContentStudioAction::ReviseImage, [
            'content_item_id' => (string) $item->getKey(),
            'instruction' => $validated['instruction'] ?? null,
            'variants' => $validated['variants'] ?? 1,
        ]);

        return response()->json([
            'plan' => $this->planProps($plan->refresh()),
            'operation' => $this->operationProps($run->refresh()),
        ], 202);
    }

    /**
     * Take a photograph an operator actually took.
     *
     * No queue and no provider: there is nothing to wait for, and a person who
     * has just chosen a file from their machine should see it appear rather
     * than watch a spinner. It lands as a candidate rather than as the picture
     * — choosing stays one deliberate act, whatever the source.
     */
    public function uploadImage(Request $request, ContentItem $item): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'max:12288'],
        ]);

        $plan = $item->contentPlan;

        if ($plan === null) {
            return response()->json(['message' => 'That draft does not belong to a plan.'], 422);
        }

        $channel = ChannelType::tryFrom((string) $item->channel_type);

        if ($channel === null) {
            return response()->json(['message' => 'That draft is not a social post.'], 422);
        }

        try {
            app(UploadedPicture::class)->store(
                $item,
                $request->file('photo'),
                ChannelPlaybook::for($channel),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['plan' => $this->planProps($plan->refresh())]);
    }

    /**
     * Make one of the candidates the picture this draft ships.
     *
     * Synchronous on purpose. There is no model and no provider in this path —
     * the decision was made by the person clicking — and queueing it would mean
     * an operator watching a spinner to record their own choice.
     */
    public function chooseImage(ContentItem $item, Asset $asset): JsonResponse
    {
        $plan = $item->contentPlan;

        if ($plan === null) {
            return response()->json(['message' => 'That draft does not belong to a plan.'], 422);
        }

        try {
            app(ContentStudioAssistant::class)->chooseImage($item, $asset);
        } catch (ContentStudioException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['plan' => $this->planProps($plan->refresh())]);
    }

    public function generate(
        ContentPlan $plan,
    ): JsonResponse {
        if (! $plan->hasAcceptedAssistantVersion()) {
            return response()->json([
                'message' => 'Accept the current proposal before generating drafts.',
            ], 422);
        }

        $run = $this->operations->start($this->project(), $plan, ContentStudioAction::Generate);

        return response()->json([
            'plan' => $this->planProps($plan->refresh()),
            'operation' => $this->operationProps($run->refresh()),
        ], 202);
    }

    /**
     * Draft one idea, now, because somebody asked for that one.
     *
     * The engine could already do this and had no door: `GenerateIdea` is
     * locked per idea, guarded per idea, and dispatched by the `generate_week`
     * fan-out — but the only route into drafting was the fan-out itself, so the
     * smallest thing an operator could ask for was a week, and the smallest
     * occasion for asking was a month. That is the whole reason the Studio was
     * a monthly ritual rather than something to open on a Tuesday.
     *
     * **No acceptance gate, unlike {@see generate()}.** The gate exists so that
     * a model's month does not become drafts without a person saying it is the
     * right month. Clicking Create on one idea *is* that person saying so, at a
     * finer grain and about the only idea it will spend money on — a stronger
     * signal than accepting twenty at once, not a way around it. Onboarding's
     * `initial` path already generates a preview idea before acceptance for a
     * related reason, so this is the existing position applied consistently
     * rather than a new one.
     *
     * A fully drafted idea is refused here rather than being dispatched to
     * discover it: {@see ContentStudioAssistant::generateIdea()} would return
     * `created: 0` after a worker had picked the run up, and the operator would
     * watch a spinner to be told nothing happened.
     */
    public function generateIdea(ContentIdea $idea): JsonResponse
    {
        $idea->loadMissing('contentPlan');

        $drafted = ContentItem::query()
            ->where('content_idea_id', $idea->getKey())
            ->pluck('channel_type')
            ->all();

        if (array_diff($idea->channels, $drafted) === []) {
            return response()->json([
                'message' => 'Every channel of this idea is already drafted.',
            ], 422);
        }

        $plan = $idea->contentPlan;

        $run = $this->operations->start($this->project(), $plan, ContentStudioAction::GenerateIdea, [
            'content_idea_id' => (string) $idea->getKey(),
        ]);

        return response()->json([
            'plan' => $this->planProps($plan->refresh()),
            'operation' => $this->operationProps($run->refresh()),
        ], 202);
    }

    /**
     * An idea somebody typed, written immediately.
     *
     * The Studio's ideas all came from one place — a model reading the site
     * once a month — so the thing that happened this morning had nowhere to go.
     * This is the other door: a title, a kind, a date, and it is drafting
     * within the second.
     *
     * **The kind decides the channels, exactly as it does for the assistant.**
     * {@see PostKind::channels()} is the same mapping the proposal is held to,
     * and letting an operator pick channels freely would let a hand-written
     * idea do the one thing every planned idea is forbidden — go everywhere,
     * which is the cross-posting this engine exists to argue against.
     *
     * Generated on the spot rather than left in the plan. An idea a person
     * typed is an idea they want now; leaving it to the weekly batch would make
     * this a slower way of doing what the Studio already did.
     */
    public function storeIdea(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:255'],
            'thesis' => ['required', 'string', 'min:3', 'max:5000'],
            'kind' => ['required', 'string', 'in:'.implode(',', array_column(PostKind::cases(), 'value'))],
            'date' => ['required', 'date'],
            // Where the reason came from, when it came from one. Scoped by the
            // `exists` rule through the tenant's own table, so a signal id from
            // another project is a validation failure rather than a silent
            // attribution to somebody else's reason.
            'signal_id' => ['nullable', 'string'],
        ]);

        $date = Carbon::parse($validated['date'])->startOfDay();
        $project = $this->project();

        $signal = ($validated['signal_id'] ?? null) === null
            ? null
            : Signal::query()->whereKey($validated['signal_id'])->first();

        if (($validated['signal_id'] ?? null) !== null && $signal === null) {
            throw ValidationException::withMessages([
                'signal_id' => 'That signal does not belong to this project.',
            ]);
        }

        $plan = ContentPlan::query()->firstOrCreate(['month' => $date->copy()->startOfMonth()]);
        $kind = PostKind::from($validated['kind']);

        $idea = $plan->contentIdeas()->create([
            // The live version, so it appears on a board and in a proposal that
            // already exists. Once it has drafts, `preserveDraftedIdeas()`
            // carries it through every later refinement — which is why this is
            // written *and generated* in one action rather than left to sit at
            // a version the next refine would drop it from.
            //
            // The plan's version, not `max(1, …)`. A month nobody has proposed
            // for is created here by `firstOrCreate()` and sits at 0, so the
            // floor of one wrote the idea to a version {@see ActionBoard::cards()}
            // does not read — and this action redirects to that very board
            // promising the idea is on it. It was not, and would not be until
            // an assistant proposal happened to arrive.
            'proposal_version' => $plan->assistant_version,
            'idea_key' => $this->ideaKey($plan, $validated['title']),
            'title' => $validated['title'],
            'kind' => $kind,
            'pillar' => 'Operator',
            'thesis' => $validated['thesis'],
            'evidence' => [],
            'goal' => 'operator',
            'audience' => (string) ($project->site_analysis['audience'] ?? ''),
            'angle' => null,
            'channels' => array_map(
                static fn (ChannelType $channel): string => $channel->value,
                $kind->channels(),
            ),
            'scheduled_for' => $date,
        ]);

        $run = $this->operations->start($project, $plan, ContentStudioAction::GenerateIdea, [
            'content_idea_id' => (string) $idea->getKey(),
            // Carried to the drafting run so the posts it writes point back at
            // the reason. `content_items.signal_id` is a real column for
            // exactly this — §3's argument that the loop learns by source only
            // works if the attribution survives the trip through the queue.
            ...($signal === null ? [] : ['signal_id' => (string) $signal->getKey()]),
        ]);

        return response()->json([
            'idea' => ['id' => (string) $idea->getKey(), 'title' => $idea->title],
            'plan' => $this->planProps($plan->refresh()),
            'operation' => $this->operationProps($run->refresh()),
        ], 202);
    }

    /**
     * Change an idea before it is written.
     *
     * The three things somebody actually adjusts when they open one and
     * disagree: what it is called, what the point of it is, and what artefact
     * it should be. Nothing else on the row is editable, because everything
     * else — the kind, the channels, the date — either follows from those or
     * would break the rule that the kind decides the channels.
     *
     * Refused once it has drafts. Changing the format of an idea whose posts
     * are already written would describe an artefact that does not exist:
     * `plannedProduction()` would promise a carousel over a single drawn
     * photograph, and the screen would be lying about what is on the row.
     * Redrawing is a different act with its own controls.
     */
    public function updateIdea(Request $request, ContentIdea $idea): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'min:3', 'max:255'],
            'thesis' => ['sometimes', 'string', 'min:3', 'max:5000'],
            // Editable, because the alternative to letting an operator write
            // one is the operator watching a picture they did not ask for get
            // drawn. 500 matches the column.
            'shot' => ['sometimes', 'nullable', 'string', 'max:500'],
            'content_format' => [
                'sometimes',
                'nullable',
                'string',
                'in:'.implode(',', array_column(ContentFormat::cases(), 'value')),
            ],
        ]);

        $written = ContentItem::query()->where('content_idea_id', $idea->getKey())->exists();

        if ($written && array_key_exists('content_format', $validated)) {
            return response()->json([
                'message' => 'This idea is already written, so its format is settled. '
                    .'Redraw the picture on the post instead.',
            ], 422);
        }

        // Read before the change so the comparison below is against what the
        // idea used to say, not against what it is about to.
        $rethought = (array_key_exists('title', $validated) && $validated['title'] !== $idea->title)
            || (array_key_exists('thesis', $validated) && $validated['thesis'] !== $idea->thesis);

        $idea->forceFill(array_filter([
            'title' => $validated['title'] ?? null,
            'thesis' => $validated['thesis'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        if (array_key_exists('shot', $validated)) {
            // An explicit answer wins, including an explicit null — that hands
            // the choice back to the writer, which is what a null shot has
            // always meant.
            $idea->shot = $validated['shot'];
        } elseif ($rethought) {
            // The shot was planned for the idea this used to be, and drafting
            // does not treat it as a suggestion: it tells the writer to make
            // *exactly* that picture and not to substitute it. So an edited
            // thesis with a stale shot does not produce a slightly-off image,
            // it produces a faithful photograph of the superseded concept.
            //
            // Cleared rather than re-planned. Re-planning means a model call
            // from a controller, and the null path is not a hole — the drafting
            // step still receives every other photograph the month has already
            // briefed and is told to differ from them. One idea choosing its
            // own subject against that list is the old behaviour for one post;
            // twenty of them choosing in parallel and blind was the bug.
            $idea->shot = null;
        }

        if (array_key_exists('content_format', $validated)) {
            // Null is a real answer here — it hands the choice back to the
            // kind — so it cannot go through the `array_filter` above.
            $idea->content_format = $validated['content_format'] === null
                ? null
                : ContentFormat::from($validated['content_format']);
        }

        $idea->save();

        return response()->json(['idea' => $this->ideaProps($idea->refresh())]);
    }

    /**
     * One idea as the panel that edits it reads it.
     *
     * @return array<string, mixed>
     */
    private function ideaProps(ContentIdea $idea): array
    {
        return [
            'id' => (string) $idea->getKey(),
            'title' => $idea->title,
            'thesis' => $idea->thesis,
            'kind_label' => $idea->kind->label(),
            'content_format' => $idea->format()->value,
            // Whether a person set it, or it is still the kind's guess. The
            // panel says "chosen" or "suggested" on the strength of this.
            'format_chosen' => $idea->content_format !== null,
            // Returned so a caller that edits the thesis can see what happened
            // to the photograph. Without it the clear is silent, and silent is
            // how the stale shot got shipped in the first place.
            'shot' => $idea->shot,
            'channels' => $idea->channels,
            'production' => $idea->plannedProduction(),
        ];
    }

    /**
     * A key that is unique within the plan.
     *
     * `idea_key` is what {@see ContentStudioAssistant::preserveDraftedIdeas()}
     * matches a frozen idea on, so two ideas sharing one would make a
     * refinement keep the wrong one.
     */
    private function ideaKey(ContentPlan $plan, string $title): string
    {
        $base = Str::slug(Str::limit($title, 100, '')) ?: 'operator-idea';
        $key = $base;
        $suffix = 2;

        $taken = $plan->contentIdeas()->pluck('idea_key')->all();

        while (in_array($key, $taken, true)) {
            $key = $base.'-'.$suffix++;
        }

        return $key;
    }

    /**
     * The operation the screen should be watching.
     *
     * An active one if there is one, and only otherwise the newest.
     *
     * The newest alone was wrong from the moment drafting became a run per
     * idea. The screen stops polling when what it is watching settles, and the
     * newest child is only the last to finish while the expensive queue runs
     * one process in creation order — which is a default, not a guarantee, and
     * stops being true the moment a child is retried or the pool is scaled.
     * Then the screen goes quiet with siblings still drafting, and their posts
     * and their failures appear on a manual reload or not at all.
     *
     * Which active run it returns does not matter: the screen reads a status to
     * decide whether to keep asking. What matters is that "anything still
     * running" is answerable, and one row can answer it.
     */
    private function latestOperation(ContentPlan $plan): ?PipelineRun
    {
        $runs = PipelineRun::query()
            ->where('pipeline', ContentStudioPipeline::key())
            ->where('input->content_plan_id', $plan->getKey())
            ->whereNotNull('input->action');

        return (clone $runs)
            ->whereIn('status', [PipelineRunStatus::Pending, PipelineRunStatus::Running])
            ->oldest()
            ->first()
            ?? $runs->latest()->first();
    }

    /** @return array<string, mixed>|null */
    private function operationProps(?PipelineRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        $action = ContentStudioAction::tryFrom((string) ($run->input['action'] ?? ''));
        $message = null;

        if ($run->status === PipelineRunStatus::Failed) {
            $class = (string) ($run->error['class'] ?? '');
            $safe = $class !== '' && is_a($class, ContentStudioException::class, true);
            $message = $safe
                ? (string) ($run->error['message'] ?? 'The Studio operation failed.')
                : match ($action) {
                    ContentStudioAction::Refine => 'The assistant could not refine this proposal right now.',
                    ContentStudioAction::Generate => 'The assistant could not generate this batch right now.',
                    // Neutral about which door it came through: an idea is
                    // drafted both by the weekly fan-out and by one Create
                    // button, and "one of this week's ideas" was a lie on the
                    // second of those.
                    ContentStudioAction::GenerateIdea => 'The assistant could not draft that idea right now.',
                    ContentStudioAction::ReviseImage => 'The picture could not be drawn right now.',
                    default => 'The assistant could not build a proposal right now.',
                };
        }

        return [
            'id' => $run->getKey(),
            'action' => $action?->value,
            'status' => $run->status->value,
            'message' => $message,
            // Which idea this run is about, while it is still about it.
            // `result.idea` carries the idea *key* and only once the run has
            // finished, so before this there was no way for a card to know it
            // was the one being written — an operator pressed Create and the
            // only feedback was a banner about the plan. Null for the three
            // plan-level actions, which are about no single idea.
            'idea_id' => is_string($run->input['content_idea_id'] ?? null)
                ? $run->input['content_idea_id']
                : null,
            'result' => $run->context['content_studio.result'] ?? null,
            'created_at' => $run->created_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function planProps(ContentPlan $plan): array
    {
        $plan->load([
            'messages' => fn ($query) => $query->orderBy('created_at'),
            'contentIdeas' => fn ($query) => $query
                ->where('proposal_version', $plan->assistant_version)
                ->orderBy('scheduled_for'),
        ]);

        $draftsByKey = ContentItem::query()
            ->where('content_plan_id', $plan->getKey())
            ->whereNotNull('content_idea_id')
            ->with(['contentIdea', 'everyAsset'])
            ->orderBy('channel_type')
            ->get()
            ->filter(static fn (ContentItem $item): bool => $item->contentIdea !== null)
            ->groupBy(static fn (ContentItem $item): string => $item->contentIdea->idea_key);

        return [
            'id' => $plan->getKey(),
            'month' => $plan->month->format('Y-m'),
            'summary' => $plan->assistant_summary,
            'strategy' => $plan->assistant_strategy,
            'version' => $plan->assistant_version,
            'accepted_version' => $plan->assistant_accepted_version,
            'accepted' => $plan->hasAcceptedAssistantVersion(),
            'proposed_at' => $plan->assistant_proposed_at?->toIso8601String(),
            'accepted_at' => $plan->assistant_accepted_at?->toIso8601String(),
            'messages' => $plan->messages->map(static fn ($message): array => [
                'id' => $message->getKey(),
                'role' => $message->role,
                'body' => $message->body,
                'version' => $message->proposal_version,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->values()->all(),
            'ideas' => $plan->contentIdeas->map(fn (ContentIdea $idea): array => [
                'id' => $idea->getKey(),
                'key' => $idea->idea_key,
                'date' => $idea->scheduled_for->toDateString(),
                'title' => $idea->title,
                'kind' => $idea->kind->value,
                'kind_label' => $idea->kind->label(),
                'pillar' => $idea->pillar,
                'thesis' => $idea->thesis,
                'evidence' => $idea->evidence,
                'goal' => $idea->goal,
                'audience' => $idea->audience,
                'angle' => $idea->angle,
                'channels' => $idea->channels,
                'production' => $idea->plannedProduction(),
                'drafts' => $draftsByKey->get($idea->idea_key, collect())->map(
                    static fn (ContentItem $item): array => [
                        'id' => $item->getKey(),
                        'channel' => $item->channel_type,
                        'body' => $item->body_markdown,
                        'payload' => $item->channel_payload,
                        'state' => $item->state->value,
                        // A carousel's panels, in the order they are published.
                        // Kept apart from the candidates below: these are the
                        // post's sequence, not alternatives to one picture, and
                        // showing them in the "other takes" strip would invite
                        // an operator to choose slide four as the cover.
                        'slides' => $item->everyAsset
                            ->filter(static fn ($asset): bool => $asset->role === AssetRole::Inline
                                && $asset->superseded_at === null)
                            ->sortBy('anchor')
                            ->map(static fn ($asset): array => [
                                'id' => $asset->getKey(),
                                'url' => $asset->url(),
                                'alt' => $asset->alt,
                            ])->values()->all(),
                        // Every picture the draft has, not only the one it
                        // ships: the review screen is where an operator chooses
                        // between them, and a candidate it cannot see is a
                        // candidate that may as well not have been drawn.
                        // everyAsset, not assets: the relation filters out
                        // superseded rows, and choosing a candidate retires the
                        // one it replaced. Reading the filtered relation made
                        // the replaced picture vanish the moment it was
                        // replaced, so "put back the one you rejected" — the
                        // reason AssetRole::Variant retires instead of
                        // deleting — was unreachable.
                        'assets' => $item->everyAsset
                            ->reject(static fn ($asset): bool => $asset->role === AssetRole::Inline)
                            ->sortBy(static fn ($asset): array => [
                                $asset->isHero() ? 0 : 1,
                                $asset->superseded_at === null ? 0 : 1,
                                (string) $asset->getKey(),
                            ])
                            ->map(static fn ($asset): array => [
                                'id' => $asset->getKey(),
                                'url' => $asset->url(),
                                'alt' => $asset->alt,
                                'width' => $asset->width,
                                'height' => $asset->height,
                                'chosen' => $asset->isHero(),
                                'retired' => $asset->superseded_at !== null,
                                'source' => $asset->source->value,
                            ])->values()->all(),
                    ],
                )->values()->all(),
            ])->values()->all(),
        ];
    }

    private function project(): Project
    {
        return $this->current->get() ?? abort(404, 'Choose a project first.');
    }

    private function month(string $value): Carbon
    {
        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
            abort(422, 'Month must be YYYY-MM.');
        }

        try {
            $month = Carbon::createFromFormat('!Y-m', $value);
        } catch (Throwable) {
            abort(422, 'Month must be YYYY-MM.');
        }

        if ($month === null || $month->format('Y-m') !== $value) {
            abort(422, 'Month must be YYYY-MM.');
        }

        return $month->startOfMonth();
    }
}
