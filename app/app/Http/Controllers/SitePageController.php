<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\PageSnapshot;
use App\Models\Project;
use App\Models\SitePage;
use App\Pages\PageDiscovery;
use App\Pages\TrackedPages;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

final class SitePageController extends Controller
{
    public function __construct(private readonly CurrentProject $current, private readonly TrackedPages $pages) {}

    public function index(): Response
    {
        $project = $this->project();

        return Inertia::render('site-pages/index', [
            'pages' => SitePage::query()->tracked()->with('latestSnapshot')->orderBy('url')->paginate(25)->through(fn (SitePage $page): array => $this->summary($page)),
            'candidates' => SitePage::query()->whereNull('tracked_at')->orderBy('url')->limit(200)->get(['id', 'url', 'title', 'is_article']),
            'locales' => array_values(array_unique([$project->default_locale, ...$project->locales])),
            'defaultLocale' => $project->default_locale,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'url:http,https', 'max:2000'], 'locale' => ['required', 'string', 'max:35'], 'kind' => ['required', 'in:commercial,editorial,other']]);
        try {
            $page = $this->pages->track($this->project(), $data['url'], $data['locale'], $data['kind']);
        } catch (UnsafePublicUrl|ConnectionException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => 'The page could not be safely read: '.$e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Page tracked and a public snapshot saved.']);

        return to_route('pages.show', $page);
    }

    public function show(SitePage $page): Response
    {
        return Inertia::render('site-pages/show', [
            'page' => $this->summary($page->load('latestSnapshot')),
            'cms' => ['channel_id' => $page->channel_id, 'object_type' => $page->cms_object_type, 'object_id' => $page->cms_object_id, 'latest' => $page->snapshots()->whereIn('source_kind', ['wordpress', 'webhook'])->first()?->only(['id', 'captured_at', 'revision', 'editable_fields', 'metadata'])],
            'connections' => Channel::query()->whereIn('type', ['wordpress', 'webhook'])->where('is_enabled', true)->get()->filter(fn (Channel $channel): bool => ! empty($channel->config['page_receiver_base']))->map(fn (Channel $channel): array => ['id' => $channel->id, 'name' => $channel->name, 'type' => $channel->type->value])->values(),
            'snapshots' => $page->snapshots()->limit(30)->get()->map(fn (PageSnapshot $snapshot): array => [
                'id' => $snapshot->id, 'captured_at' => $snapshot->captured_at->toIso8601String(),
                'source_kind' => $snapshot->source_kind, 'source_url' => $snapshot->source_url,
                'revision' => $snapshot->revision, 'content_hash' => $snapshot->content_hash,
                'fields' => $snapshot->fields, 'editable_fields' => $snapshot->editable_fields,
            ]),
        ]);
    }

    public function capture(SitePage $page): RedirectResponse
    {
        abort_unless($page->tracked_at !== null && $page->locale !== null, 422, 'Track this page before capturing another snapshot.');
        try {
            $this->pages->capture($this->project(), $page);
        } catch (UnsafePublicUrl|ConnectionException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['url' => 'The page could not be safely read: '.$e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => 'A new immutable public snapshot was saved.']);

        return back();
    }

    public function untrack(SitePage $page): RedirectResponse
    {
        DB::transaction(function () use ($page): void {
            Project::query()->whereKey($this->current->id())->lockForUpdate()->firstOrFail();
            SitePage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail()->update(['tracked_at' => null]);
        });
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Tracking paused. Snapshots and publication history are preserved.']);

        return to_route('pages.index');
    }

    public function discover(PageDiscovery $discovery): RedirectResponse
    {
        try {
            $result = $discovery->discover($this->project());
        } catch (UnsafePublicUrl|ConnectionException|InvalidArgumentException $e) {
            throw ValidationException::withMessages(['sitemap' => 'The sitemap could not be safely read: '.$e->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $result['found'].' same-site URLs discovered. Select the pages to track.'.($result['limited'] ? ' Discovery stopped at 200 URLs or five sitemaps.' : '').($result['skipped'] > 0 ? ' '.$result['skipped'].' unsafe or off-site entries were skipped.' : '')]);

        return back();
    }

    private function project(): Project
    {
        return $this->current->get() ?? abort(404);
    }

    /** @return array<string, mixed> */
    private function summary(SitePage $page): array
    {
        return [
            'id' => $page->id, 'title' => $page->title, 'url' => $page->url,
            'canonical_url' => $page->canonical_url, 'locale' => $page->locale,
            'kind' => $page->page_kind->value ?? 'other', 'tracked_at' => $page->tracked_at?->toIso8601String(),
            'content_item_id' => $page->content_item_id, 'snapshot_at' => $page->latestSnapshot?->captured_at->toIso8601String(),
            'snapshot_count' => $page->snapshots()->count(),
        ];
    }
}
