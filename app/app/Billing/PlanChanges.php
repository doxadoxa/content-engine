<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\BillingStatus;
use App\Models\ArticleSchedule;
use App\Models\ProjectSubscription;

final class PlanChanges
{
    public function atRenewal(?ProjectSubscription $subscription, Plan $target): bool
    {
        if ($subscription === null || $subscription->status === BillingStatus::Trialing) {
            return false;
        }
        $current = $subscription->plan();
        if ($current->currency === $target->currency && $target->priceCents < $current->priceCents) {
            return true;
        }
        foreach (['articles', 'page_improvements', 'social_posts', 'locales', 'seats', 'channels'] as $metric) {
            if ($target->limit($metric) !== null && ($current->limit($metric) === null || $target->limit($metric) < $current->limit($metric))) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function preview(?ProjectSubscription $subscription, Plan $target): array
    {
        $renewal = $this->atRenewal($subscription, $target);
        $schedules = $renewal && $subscription?->period_ends_at !== null
            ? ArticleSchedule::acrossProjects()->where('project_id', $subscription->project_id)
                ->where('publish_at', '>=', $subscription->period_ends_at)
                ->whereIn('status', ['active', 'blocked', 'dispatching', 'paused'])->with('contentItem')->orderBy('publish_at')->get()
            : collect();

        return [
            'at_renewal' => $renewal,
            'effective_at' => $renewal ? $subscription?->period_ends_at?->toIso8601String() : null,
            'schedules' => $schedules->map(static fn (ArticleSchedule $schedule): array => [
                'id' => $schedule->id,
                'title' => $schedule->contentItem->title,
                'publish_at' => $schedule->publish_at->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
