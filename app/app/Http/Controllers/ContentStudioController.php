<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\ContentStudio\ContentStudioAction;
use App\ContentStudio\ContentStudioAssistant;
use App\ContentStudio\ContentStudioConflict;
use App\ContentStudio\ContentStudioException;
use App\ContentStudio\ContentStudioOperations;
use App\Enums\PipelineRunStatus;
use App\Models\ContentIdea;
use App\Models\ContentItem;
use App\Models\ContentPlan;
use App\Models\PipelineRun;
use App\Models\Project;
use App\Pipelines\Definitions\ContentStudioPipeline;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
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

        return Inertia::render('studio/index', [
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

            return response()->json(['plan' => $this->planProps($updated)]);
        } catch (ContentStudioConflict $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ContentStudioException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'The proposal could not be accepted right now.'], 500);
        }
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

    private function latestOperation(ContentPlan $plan): ?PipelineRun
    {
        return PipelineRun::query()
            ->where('pipeline', ContentStudioPipeline::key())
            ->where('input->content_plan_id', $plan->getKey())
            ->whereNotNull('input->action')
            ->latest()
            ->first();
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
                    default => 'The assistant could not build a proposal right now.',
                };
        }

        return [
            'id' => $run->getKey(),
            'action' => $action?->value,
            'status' => $run->status->value,
            'message' => $message,
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
            ->with(['contentIdea', 'assets'])
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
                        'assets' => $item->assets->map(static fn ($asset): array => [
                            'id' => $asset->getKey(),
                            'url' => $asset->url(),
                            'alt' => $asset->alt,
                            'width' => $asset->width,
                            'height' => $asset->height,
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
