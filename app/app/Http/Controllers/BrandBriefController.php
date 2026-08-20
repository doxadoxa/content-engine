<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BrandBriefRequest;
use App\Models\BrandBrief;
use App\Models\Project;
use App\Onboarding\Jobs\ReadSitePalette;
use App\Onboarding\SiteScreenshot;
use App\Support\Brand\SitePalette;
use App\Support\Brand\VisualStyle;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
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
                'paletteReading' => false,
                'paletteOutcome' => null,
                'paletteColours' => [],
                'siteFont' => null,
                'typefaces' => $this->typefaces(),
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
            // The two halves of a read that no longer finishes inside the
            // request: whether one is running, and what the last one said. The
            // screen polls on the first and prints the second — a toast cannot
            // carry the answer any more, because by the time it arrives the
            // response that would have flashed it is long since delivered.
            'paletteReading' => $this->isReading($project),
            'paletteOutcome' => is_array($project->site_analysis['palette_outcome'] ?? null)
                ? $project->site_analysis['palette_outcome']
                : null,
            // Everything the site declares, not just the three a panel uses. The
            // brief has three slots because a renderer needs three; a brand has
            // as many colours as it has, and the person choosing between them is
            // the one who should see the rest rather than only our arithmetic on
            // it.
            'paletteColours' => array_values(array_filter(
                is_array($project->site_analysis['palette_colours'] ?? null)
                    ? $project->site_analysis['palette_colours']
                    : [],
                static fn (mixed $hex): bool => is_string($hex) && preg_match('/^#[0-9a-f]{6}$/i', $hex) === 1,
            )),
            'siteFont' => is_string($project->site_analysis['brand_font'] ?? null)
                ? $project->site_analysis['brand_font']
                : null,
            // What the renderer's image actually carries. Sent rather than
            // hardcoded in the screen so the list has one home: adding a face
            // is two woff2 files and a line in VisualStyle, not three edits.
            'typefaces' => $this->typefaces(),
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
     *
     * **Dispatched, not done here.** The work is a browser opening somebody
     * else's website, which {@see SiteScreenshot} allows two minutes for — see
     * {@see ReadSitePalette} for why that cannot live in a request. What this
     * keeps is the pair of refusals that need no browser to answer.
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

        // Stamped before dispatch rather than by the job, so the screen that
        // caused this comes back already showing it is reading. A worker busy
        // with something else would otherwise leave the button looking like it
        // had not been pressed, and the second press is the one that queues two
        // browsers against the same site.
        //
        // The previous outcome goes with it: it described the last read, and
        // leaving it on screen underneath a running one is a stale answer to a
        // question being asked again.
        $project->forceFill([
            'site_analysis' => [
                ...$project->site_analysis,
                'palette_reading_at' => now()->toIso8601String(),
                'palette_outcome' => null,
            ],
        ])->save();

        ReadSitePalette::dispatch((string) $project->getKey());

        return $this->toast('info', 'Reading your site — this takes a few seconds.');
    }

    /**
     * Whether a read is in flight, with a floor under it.
     *
     * The flag is cleared by {@see ReadSitePalette} on every path it can reach,
     * including its own failure — but a worker killed outright reaches none of
     * them, and a flag stuck on is a screen that polls for an answer that is
     * never coming. So the flag expires: past this, the last read is treated as
     * gone rather than running, and the operator gets their button back.
     *
     * Comfortably longer than the job's own 180-second timeout, because a job
     * still working is not a job that died.
     */
    private function isReading(Project $project): bool
    {
        $startedAt = $project->site_analysis['palette_reading_at'] ?? null;

        if (! is_string($startedAt)) {
            return false;
        }

        return Carbon::parse($startedAt)->gt(now()->subMinutes(5));
    }

    private function toast(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return to_route('brief.edit');
    }

    /**
     * The faces the renderer's image carries, for the screen to offer.
     *
     * Sent rather than restated in the interface so the list has one home:
     * adding a face is two woff2 files under `resources/fonts/<slug>/` and a
     * line in {@see VisualStyle::TYPEFACES}, not a third edit here that somebody
     * forgets and a select that then offers a font nothing can draw.
     *
     * @return list<array{slug: string, name: string}>
     */
    private function typefaces(): array
    {
        return array_map(
            static fn (string $slug, string $name): array => ['slug' => $slug, 'name' => $name],
            array_keys(VisualStyle::TYPEFACES),
            array_values(VisualStyle::TYPEFACES),
        );
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
            'brand_palette' => $brief->brand_palette,
            'brand_typeface' => $brief->brand_typeface,
            'carousel_cover' => $brief->carousel_cover,
            'overlay_position' => $brief->overlay_position,
            'overlay_case' => $brief->overlay_case,
            'forbidden_topics' => $brief->forbidden_topics,
            'examples_liked' => $brief->examples_liked,
            'examples_disliked' => $brief->examples_disliked,
            'competitors' => $brief->competitors,
        ];
    }
}
