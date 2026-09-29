<?php

declare(strict_types=1);

use App\Http\Controllers\Blog\BlogController;
use App\Http\Controllers\Blog\BlogWebhookController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\VerifyBlogSignature;
use App\Models\BlogPost;
use Illuminate\Support\Facades\Route;

/*
 * Avyo's own blog. Public, and written by Avyo: a webhook channel on the
 * project that runs Avyo's marketing points at `blog/webhook`, and the engine
 * publishes here exactly as it would to any customer's receiver.
 *
 * The webhook is authenticated by the channel secret and an HMAC over the raw
 * body, not by a session — hence its CSRF exemption in bootstrap/app.php.
 */
Route::post('blog/webhook', BlogWebhookController::class)
    ->middleware(['throttle:120,1', VerifyBlogSignature::class])->name('blog.webhook');

Route::get('blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('blog/feed.xml', [BlogController::class, 'feed'])->name('blog.feed');
// The same patterns the webhook stores posts under (BlogInbox), so nothing is
// accepted there that has no address here.
Route::get('blog/{slug}', [BlogController::class, 'show'])
    ->where('slug', BlogPost::SLUG_PATTERN)->name('blog.show');
Route::get('blog/{locale}/{slug}', [BlogController::class, 'showLocalized'])
    ->where(['locale' => BlogPost::LOCALE_PATTERN, 'slug' => BlogPost::SLUG_PATTERN])
    ->name('blog.show.localized');

Route::get('sitemap.xml', SitemapController::class)->name('sitemap');
