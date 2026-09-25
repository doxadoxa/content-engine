<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Enums\ChannelType;
use App\Enums\ContentItemState;
use App\Integrations\Google\GooglePanel;
use App\Models\BrandBrief;
use App\Models\BusinessFact;
use App\Models\Channel;
use App\Models\ContentItem;
use App\Models\PageProposal;
use App\Models\Project;
use App\Models\PurchaseSource;
use App\Models\SitePage;
use App\Support\Engine\ArticleWorkflow;

final class WebsiteChecklist
{
    /** @return list<array<string, mixed>> */
    public static function for(Project $project): array
    {
        $google = app(GooglePanel::class)->connectionState($project);
        if (! ArticleWorkflow::enabled($project)) {
            return self::focused($project, $google);
        }
        $websiteTypes = array_map(
            static fn (ChannelType $type): string => $type->value,
            ChannelType::cases(),
        );
        $drafts = ContentItem::query()->inState(ContentItemState::Draft)->exists();
        $reviewed = ContentItem::query()->whereIn('state', [
            ContentItemState::Approved->value,
            ContentItemState::Published->value,
        ])->exists();

        return [
            self::step('brief', 'Understand the business', 'Confirm the business brief',
                'Check the services, customers, and claims used to prepare recommendations.',
                BrandBrief::activeFor($project) !== null, '/brief', 'Review the brief'),
            self::step('analysis', 'Understand the business', 'Read the existing website',
                'Use the website and customer questions to plan useful new articles.',
                SitePage::query()->exists(), '/audit', 'Review the website'),
            self::step('google', 'See what it did', 'Connect Search Console and Analytics',
                'Search and visitor data help explain which pages need attention. Purchases need their own verified tracking.',
                $google['search_console'] && $google['analytics'],
                '/projects/'.$project->getKey().'/edit', 'Connect Google'),
            self::step('channel', 'Publish reviewed changes', 'Connect the website',
                'Connect article publishing to your website. Scheduled articles wait until the connection is ready.',
                Channel::query()->whereIn('type', $websiteTypes)->where('is_enabled', true)->whereNotNull('verified_at')->exists(),
                '/channels', 'Website connections'),
            self::step('approve', 'Publish reviewed changes', 'Review the waiting content',
                $project->autopublish ? 'Articles that pass their checks publish on schedule. Review anything that needs attention.' : 'Approve articles before their scheduled publication.',
                $reviewed, '/calendar', 'Open calendar',
                ! $drafts && ! $reviewed ? 'A draft is needed before it can be reviewed.' : null),
        ];
    }

    /** @param array<string,mixed> $google
     * @return list<array<string,mixed>>
     */
    private static function focused(Project $project, array $google): array
    {
        $tracked = SitePage::query()->tracked()->exists();
        $facts = BusinessFact::query()->with('currentVersion')->get()->contains(fn ($fact): bool => $fact->currentVersion?->isUsable() === true);
        $accepted = PageProposal::query()->whereNotNull('approved_revision_id')->exists();

        return [
            self::step('tracked_page', 'Choose the starting page', 'Track an existing customer page', 'Choose a service, pricing or useful article page. Keep its existing address and language.', $tracked, '/pages', 'Track a page'),
            self::step('confirmed_facts', 'Confirm the business', 'Confirm the facts a customer needs', 'Record current services, scope, prices or policies with their source and review date.', $facts, '/business-facts', 'Review business facts'),
            self::step('google', 'Measure the result', 'Connect Search Console and Analytics', 'Choose your exact properties; missing observations remain unavailable.', $google['search_console'] && $google['analytics'], '/projects/'.$project->id.'/edit', 'Connect Google'),
            self::step('purchases', 'Measure the result', 'Choose a purchase source', 'Use recorded paid purchases as the primary outcome. Analytics is supplementary.', PurchaseSource::query()->where('is_primary', true)->where('is_enabled', true)->exists(), '/purchases', 'Set up purchases'),
            self::step('proposal', 'Review a bounded improvement', 'Review the strongest page opportunity', 'Prepare and review a specific justified change. Acceptance uses one improvement; publication needs a separate action.', $accepted, '/plan', 'Open Plan', ! $tracked || ! $facts ? 'Track a page and confirm supporting business facts first.' : null),
            self::step('channel', 'Publish reviewed changes', 'Connect the exact website page', 'Bind a supported WordPress or custom object, or use an assisted handoff.', SitePage::query()->tracked()->whereNotNull('channel_id')->whereNotNull('cms_object_id')->exists(), '/pages', 'Set up page publishing'),
        ];
    }

    /** @return array<string, mixed> */
    private static function step(string $key, string $group, string $label, string $detail, bool $done, string $action, string $actionLabel, ?string $blockedBy = null): array
    {
        return [
            'key' => $key,
            'group' => $group,
            'label' => $label,
            'detail' => $detail,
            'done' => $done,
            'locked' => $blockedBy !== null,
            'blocked_by' => $blockedBy,
            'action' => $done ? null : $action,
            'action_label' => $done ? null : $actionLabel,
        ];
    }
}
