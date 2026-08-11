<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\BrandBriefRequest;
use App\Models\BrandBrief;
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
            ]);
        }

        // withCount rather than loading the items: the history shows how many
        // publications rode on each version, and a project with a year of
        // output would otherwise hydrate every one of them to print a number.
        $versions = $project->brandBriefs()->withCount('contentItems')->get();

        $active = $versions->firstWhere('is_active', true);

        return Inertia::render('brief/edit', [
            'brief' => $active === null ? null : $this->toFormProps($active),
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
            'overlay_position' => $brief->overlay_position,
            'overlay_case' => $brief->overlay_case,
            'forbidden_topics' => $brief->forbidden_topics,
            'examples_liked' => $brief->examples_liked,
            'examples_disliked' => $brief->examples_disliked,
            'competitors' => $brief->competitors,
        ];
    }
}
