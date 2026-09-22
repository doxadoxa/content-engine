<?php

declare(strict_types=1);

namespace App\Pages;

use App\Models\Project;
use App\Models\SitePage;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PageDiscovery
{
    public function __construct(private readonly PublicHttpClient $http, private readonly PublicHttpTarget $targets, private readonly CurrentProject $current) {}

    /** @return array{found: int, skipped: int, limited: bool} */
    public function discover(Project $project): array
    {
        $origin = $project->website_url ?: $project->sitemap_url;
        if (! $origin) {
            throw ValidationException::withMessages(['sitemap' => 'Set a website address in project settings first.']);
        }
        $queue = [$project->sitemap_url ?: $this->targets->origin($origin).'/sitemap.xml'];
        $seen = [];
        $urls = [];
        $skipped = 0;
        while ($queue !== [] && count($seen) < 5 && count($urls) < 200) {
            $sitemap = array_shift($queue);
            if (isset($seen[$sitemap])) {
                continue;
            }
            $seen[$sitemap] = true;
            $response = $this->http->request('GET', $sitemap, ['Accept' => 'application/xml,text/xml', 'User-Agent' => 'Avyo/1.0 (+page-discovery)'], 15, 3, $origin, 2_000_000)->response;
            if (! $response->successful() || strlen($response->body()) > 2_000_000) {
                throw ValidationException::withMessages(['sitemap' => 'The sitemap could not be read within the 2 MB limit. Import selected page URLs directly instead.']);
            }
            $previous = libxml_use_internal_errors(true);
            try {
                // No external entity expansion, remote DTDs, or XML entity substitution.
                $xml = simplexml_load_string($response->body(), options: LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            if ($xml === false || ! in_array($xml->getName(), ['urlset', 'sitemapindex'], true)) {
                throw ValidationException::withMessages(['sitemap' => 'This address did not return a supported sitemap. Import selected page URLs directly instead.']);
            }
            foreach ($xml->children() as $entry) {
                try {
                    $url = PageUrl::normalize((string) $entry->loc);
                    $this->targets->validate($url, $origin);
                } catch (UnsafePublicUrl|InvalidArgumentException) {
                    $skipped++;

                    continue;
                }
                if ($xml->getName() === 'sitemapindex') {
                    $queue[] = $url;
                } else {
                    $urls[$url] = true;
                    if (count($urls) >= 200) {
                        break;
                    }
                }
            }
        }
        $this->current->run($project, function () use ($urls): void {
            foreach (array_keys($urls) as $url) {
                SitePage::query()->firstOrCreate(['url' => $url], ['title' => Str::limit($url, 250, '')]);
            }
        });

        return ['found' => count($urls), 'skipped' => $skipped, 'limited' => $queue !== [] || count($urls) >= 200];
    }
}
