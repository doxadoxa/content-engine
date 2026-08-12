<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Content\ArticleScore;
use App\Enums\AssetRole;
use App\Enums\PipelineStepStatus;
use App\Enums\RejectionReason;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\PipelineRun;
use App\Models\WebhookDelivery;
use App\Publishing\PublishToChannels;
use App\Support\Content\ContentItemProps;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The unit card (§7): the draft itself, its languages, its derivatives, the
 * brief version behind it, and what happened when it was delivered.
 */
class ContentItemDetailController extends Controller
{
    public function __construct(
        private readonly ArticleScore $score,
        private readonly PublishToChannels $channels,
    ) {}

    public function __invoke(ContentItem $item): Response
    {
        $item->load(['localeVariants', 'derivatives', 'assets']);

        $brief = $item->brand_brief_id === null
            ? null
            : BrandBrief::query()->whereKey($item->brand_brief_id)->first();

        return Inertia::render('content/show', [
            'item' => [
                ...ContentItemProps::summary($item),
                'summary' => $item->summary,
                'body_markdown' => $item->body_markdown,
                'body_html' => $item->body_html,
                'outline' => $item->outline,
                'json_ld' => $item->json_ld,
                'faq_json_ld' => $item->faq_json_ld,
                'quotable_blocks' => $item->quotable_blocks,
                'entity_coverage' => $item->entity_coverage,
                'factcheck' => $item->factcheck,
                'author' => $item->author,
                'review' => $item->review,
                'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                'cluster' => $item->cluster,
                'intent' => $item->intent?->value,
                // The picture, if it has one. An article page without it is a
                // wall of text, which is not what anybody is about to publish.
                'hero' => $this->hero($item),
                // The checklist and the number over it, read off the finished
                // article rather than off the steps that were meant to produce
                // it — "the GEO step ran" and "there is FAQ schema on this
                // page" are different claims.
                ...$this->score->for($item),
                'data' => $this->score->data($item),
            ],
            // The closed set behind "send back". Passed here as well as to the
            // approvals queue because an approved article is not in that queue
            // — this page is the only place a human meets it after signing it
            // off, and until now the only thing they could do about a fault was
            // publish it anyway.
            'reasons' => array_map(
                static fn (RejectionReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                ],
                RejectionReason::cases(),
            ),
            // §2's promise made visible: which voice this was written from.
            'brief' => $brief === null ? null : [
                'id' => $brief->getKey(),
                'version' => $brief->version,
                'is_active' => $brief->is_active,
                'tone' => $brief->tone,
            ],
            'locales' => $item->localeVariants
                ->sortBy('locale')
                ->map(fn (ContentItem $sibling): array => [
                    'id' => $sibling->getKey(),
                    'locale' => $sibling->locale,
                    'state' => $sibling->state->value,
                    'state_label' => $sibling->state->label(),
                    'is_self' => $sibling->is($item),
                ])->values()->all(),
            'derivatives' => $item->derivatives
                ->map(fn (ContentItem $child): array => [
                    'id' => $child->getKey(),
                    'title' => $child->title,
                    'type_label' => $child->type->label(),
                    'state' => $child->state->value,
                ])->values()->all(),
            'deliveries' => WebhookDelivery::query()
                ->where('content_item_id', $item->getKey())
                ->latest()
                ->get()
                ->map(fn (WebhookDelivery $delivery): array => [
                    'id' => $delivery->getKey(),
                    'delivery_id' => $delivery->delivery_id,
                    'status' => $delivery->status->value,
                    'status_label' => $delivery->status->label(),
                    'response_code' => $delivery->response_code,
                    'attempts' => $delivery->attempts,
                    // The reason, not just the state. "Dead letter" without it
                    // sends an operator to the logs to find out that their
                    // endpoint answered 405.
                    'error' => $delivery->error,
                    'created_at' => $delivery->created_at?->toIso8601String(),
                ])->all(),
            // Whether the engine is writing this one right now. The state
            // badge cannot say it: a unit being rewritten is a `draft`, exactly
            // like one that is finished and waiting, so sending an article back
            // changed the badge to something it had been before and the screen
            // looked identical to a button that had done nothing.
            'rewriting' => $this->rewriting($item),
            'manual_channels' => $this->channels->manualTargets($item),
        ]);
    }

    /**
     * The run currently writing this unit, as steps done out of total.
     *
     * `inFlight()` rather than "not terminal", for the same reason the
     * dashboard uses it: a status column can say `running` about a run nothing
     * is running, and a progress bar that never finishes is worse than none.
     *
     * @return array{done: int, total: int}|null
     */
    private function rewriting(ContentItem $item): ?array
    {
        $run = PipelineRun::query()
            ->where('content_item_id', $item->getKey())
            ->inFlight()
            ->with('steps')
            ->latest()
            ->first();

        if ($run === null) {
            return null;
        }

        return [
            'done' => $run->steps->whereIn('status', [
                PipelineStepStatus::Succeeded,
                PipelineStepStatus::Skipped,
            ])->count(),
            'total' => $run->steps->count(),
        ];
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    private function hero(ContentItem $item): ?array
    {
        $asset = $item->assets->firstWhere('role', AssetRole::Hero);

        return $asset === null ? null : ['url' => $asset->url(), 'alt' => $asset->alt ?? $item->title];
    }
}
