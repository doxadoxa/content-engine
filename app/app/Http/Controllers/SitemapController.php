<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BlogPost;
use Illuminate\Http\Response;

/*
 * The public site, as a list a crawler can walk: the landing page, the blog,
 * the three legal documents and every live article in every language.
 *
 * Only pages anybody can read without an account. The product is behind a
 * login and a sitemap entry for it would be a list of redirects.
 *
 * URLs come from APP_URL, the same base the blog's canonicals use, so the
 * sitemap never lists an address the page itself disowns.
 */
final class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        // One query for every article; bodies are not needed to list them.
        $posts = BlogPost::query()->live()
            ->get(['id', 'locale_group_id', 'locale', 'slug', 'published_at', 'updated_at']);

        $groups = $posts->groupBy('locale_group_id');
        $newest = $posts->where('locale', (string) config('blog.locale', 'en'))->max('updated_at');

        $entries = [
            ['loc' => $this->absolute(route('home', absolute: false)), 'lastmod' => null, 'alternates' => []],
            ['loc' => $this->absolute(route('blog.index', absolute: false)), 'lastmod' => $newest?->toAtomString(), 'alternates' => []],
        ];

        foreach (['terms', 'privacy', 'cookies'] as $document) {
            $updated = config("legal.updated.{$document}");

            $entries[] = [
                'loc' => $this->absolute(route("legal.{$document}", absolute: false)),
                'lastmod' => is_string($updated) && $updated !== '' ? $updated : null,
                'alternates' => [],
            ];
        }

        foreach ($posts as $post) {
            $entries[] = [
                'loc' => $post->publicUrl(),
                'lastmod' => $post->updated_at->toAtomString(),
                'alternates' => BlogPost::hreflang($groups->get($post->locale_group_id, [])),
            ];
        }

        return response()
            ->view('sitemap', ['entries' => $entries])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function absolute(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }
}
