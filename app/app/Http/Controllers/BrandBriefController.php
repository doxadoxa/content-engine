<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BrandBriefRequest;
use App\Models\BrandBrief;
use App\Onboarding\SiteScreenshot;
use App\Support\Brand\SitePalette;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Brand Brief screen: the live version in a form, every past version
 * underneath it.
 *
 * There is no delete and no direct edit of an old version, which is the whole
 * point of the model — see {@see BrandBrief}.
 */
class BrandBriefController extends Controller
{
    public function __construct(private readonly CurrentProject $current) {}

    public function edit(): Response
    {
        $project = $this->current->get();

        if ($project === null) {
            return Inertia::render('brief/edit', [
                'brief' => null,
                'versions' => [],
                'palette' => null,
            ]);
        }

        // withCount rather than loading the items: the history shows how many
        // publications rode on each version, and a project with a year of
        // output would otherwise hydrate every one of them to print a number.
        $versions = $project->brandBriefs()->withCount('contentItems')->get();

        $active = $versions->firstWhere('is_active', true);

        return Inertia::render('brief/edit', [
            'brief' => $active === null ? null : $this->toFormProps($active),
            // Offered, never applied. A wrong fill is not a visible error — it
            // silently becomes every carousel for a month — so the colours
            // counted off the site sit beside the fields as something to click,
            // and a person decides. Null where the site was analysed before
            // there was a browser to photograph it with.
            'palette' => is_array($project->site_analysis['palette'] ?? null)
                ? $project->site_analysis['palette']
                : null,
            'versions' => $versions->map(fn (BrandBrief $brief): array => [
                ...$this->toFormProps($brief),
                'version' => $brief->version,
                'is_active' => $brief->is_active,
                'change_note' => $brief->change_note,
                'created_at' => $brief->created_at?->toIso8601String(),
                'publications' => (int) $brief->getAttribute('content_items_count'),
            ])->values()->all(),
        ]);
    }

    public function update(BrandBriefRequest $request): RedirectResponse
    {
        $project = $this->current->get();

        // No project, nothing to revise. 409 rather than 404: the route exists
        // and the operator is allowed here, they just have no tenant selected.
        abort_if($project === null, 409, 'Pick a project before editing its brief.');

        $validated = $request->safe();

        $brief = BrandBrief::revise(
            $project,
            $validated->except('change_note'),
            $validated->string('change_note')->trim()->value() ?: null,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Brief saved as version {$brief->version}.",
        ]);

        return to_route('brief.edit');
    }

    /**
     * Look at the site again and re-read its colours.
     *
     * **The colours, and nothing else.** The obvious implementation — re-run
     * `SiteAnalyst::analyse()` — would overwrite the whole of `site_analysis`:
     * the name, the description, the audiences, the seed keywords, every one of
     * which the operator corrected by hand in the wizard and none of which they
     * asked to have re-guessed. A button labelled "read my colours" that
     * silently replaced a month of corrections would be the worst kind of
     * destructive, because nothing on screen would show what it had taken.
     *
     * So this touches one key. The rest of the analysis is left exactly as the
     * operator left it.
     *
     * Still only a suggestion when it lands. It refreshes what the swatches
     * offer; applying them stays a click, for the reason
     * {@see SitePalette} gives — a wrong fill is not a visible error, it
     * quietly becomes every carousel for a month.
     */
    public function palette(SiteScreenshot $screenshots): RedirectResponse
    {
        $project = $this->current->get();

        abort_if($project === null, 409, 'Pick a project before reading its site.');

        $url = trim((string) $project->website_url);

        if ($url === '') {
            return $this->toast('error', 'This project has no website address to read.');
        }

        if (! $screenshots->isConfigured()) {
            // Named rather than shrugged at. "It did not work" sends an
            // operator looking at their own site; this sends them at the thing
            // that is actually missing.
            return $this->toast('error', 'No renderer is configured, so the site cannot be photographed.');
        }

        try {
            $png = $screenshots->of($url);
        } catch (UnsafePublicUrl $e) {
            return $this->toast('error', $e->getMessage());
        }

        if ($png === null) {
            return $this->toast('error', 'That page could not be opened in a browser.');
        }

        $palette = SitePalette::fromPng($png);

        // Written either way, and the null case is the one that matters. A read
        // that succeeded and found nothing is an *answer*, so leaving the last
        // suggestion in place would keep offering a colour the engine no longer
        // stands behind — and the operator would have no way to tell the two
        // apart, because a stale swatch looks exactly like a fresh one.
        //
        // The failures above return before this on purpose: a browser that
        // could not open the page has learned nothing, and forgetting a good
        // suggestion because the site was down for a minute is its own bug.
        $project->forceFill([
            'site_analysis' => [...$project->site_analysis, 'palette' => $palette?->toArray()],
        ])->save();

        if ($palette === null) {
            // Told apart from the failure above, because they are not the same
            // thing and only one of them is a fault. A page whose colour lives
            // in a button and a photograph rather than in any painted surface
            // has nothing this can count — which is a fact about the page, and
            // an operator told "no colour" goes looking for a bug they will not
            // find.
            return $this->toast(
                'info',
                'Read your site, but it paints no large area in a colour of its own — nothing to suggest.',
            );
        }

        return $this->toast('success', 'Read the colours from your site.');
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('brief.edit');
    }

    /**
     * @return array<string, mixed>
     */
    private function toFormProps(BrandBrief $brief): array
    {
        return [
            'id' => $brief->id,
            'positioning' => $brief->positioning,
            'audience' => $brief->audience,
            'tone' => $brief->tone,
            'visual_language' => $brief->visual_language,
            'brand_colour' => $brief->brand_colour,
            'brand_ink' => $brief->brand_ink,
            'brand_accent' => $brief->brand_accent,
            'overlay_position' => $brief->overlay_position,
            'overlay_case' => $brief->overlay_case,
            'forbidden_topics' => $brief->forbidden_topics,
            'examples_liked' => $brief->examples_liked,
            'examples_disliked' => $brief->examples_disliked,
            'competitors' => $brief->competitors,
        ];
    }
}
