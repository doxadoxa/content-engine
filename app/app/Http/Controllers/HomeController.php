<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContentItemState;
use App\Enums\DeliveryStatus;
use App\Enums\PostKind;
use App\Models\ContentItem;
use App\Models\WebhookDelivery;
use App\Social\ActivationChecklist;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where somebody starts.
 *
 * Three bands, in the order a person needs them: something to type into, a
 * checklist that says what is not set up yet, and the handful of things waiting
 * on a human today.
 *
 * **It does not replace the dashboard.** The plan said it would absorb it, and
 * building it argued the other way: the dashboard reports on the *engine* — runs
 * in flight, search impressions, citation coverage, Google connections, stack
 * health — and most of that is the article half of this product, which this
 * release is under instructions not to disturb. Folding it in would have meant
 * either dropping those cards or making Home a second dashboard with a chat box
 * on top. Home answers "what should I do", the dashboard answers "what is the
 * engine doing", and those are different questions asked at different times.
 */
class HomeController extends Controller
{
    public function __invoke(CurrentProject $current): Response
    {
        $project = $current->get();

        if ($project === null) {
            return Inertia::render('home/index', [
                'project' => null,
                'checklist' => [],
                'waiting' => [],
                'kinds' => [],
            ]);
        }

        return Inertia::render('home/index', [
            'project' => [
                'name' => $project->name,
                'site_name' => (string) ($project->site_analysis['name'] ?? $project->name),
            ],
            'checklist' => ActivationChecklist::for($project, Carbon::now()->startOfMonth()),
            'waiting' => Inertia::defer(fn (): array => $this->waiting()),
            // The kinds an operator may write by hand, each with the channels
            // it goes to — because the kind decides the channels here exactly
            // as it does in a proposal, and the chip has to say so before
            // somebody picks one expecting all three.
            'kinds' => array_map(
                static fn (PostKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'channels' => array_map(
                        static fn ($channel): string => $channel->value,
                        $kind->channels(),
                    ),
                ],
                PostKind::cases(),
            ),
        ]);
    }

    /**
     * The few things that actually need a person today.
     *
     * Counts and a handful of rows rather than a queue: this band exists to say
     * *that* there is work and where it is, and the screens it points at are
     * the ones built to do it. A third copy of the approvals list on the
     * landing page would be the duplication this release is removing.
     *
     * @return array<string, mixed>
     */
    private function waiting(): array
    {
        $drafts = ContentItem::query()
            ->social()
            ->inState(ContentItemState::Draft)
            ->orderByRaw('scheduled_for is null, scheduled_for asc')
            ->limit(5)
            ->get(['id', 'title', 'channel_type', 'scheduled_for']);

        return [
            'social_drafts' => $drafts->map(static fn (ContentItem $item): array => [
                'id' => (string) $item->getKey(),
                'title' => $item->title,
                'channel' => $item->channel_type,
                'scheduled_for' => $item->scheduled_for?->toDateString(),
            ])->values()->all(),
            'social_draft_count' => ContentItem::query()->social()
                ->inState(ContentItemState::Draft)->count(),
            // Articles are counted, never listed. The article half of this
            // engine has its own queue and its own card, and this release does
            // not touch either — but an operator whose morning includes three
            // articles should not have to open another screen to find out.
            'article_draft_count' => ContentItem::query()->roots()
                ->inState(ContentItemState::Draft)->count(),
            'failed_deliveries' => WebhookDelivery::query()
                ->whereIn('status', [DeliveryStatus::DeadLetter->value])
                ->count(),
        ];
    }
}
