<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Publishing\Articles\ArticleSchedules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * "Publish now": one article, to the project's website, this minute.
 *
 * Owner-only (see the route), because it approves as well as sends. The work
 * and every refusal live in {@see ArticleSchedules::publishNow()}.
 */
final class PublishNowController extends Controller
{
    public function __invoke(Request $request, ContentItem $item, ArticleSchedules $schedules): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        $schedule = $schedules->publishNow($actor, $item);

        Inertia::flash('toast', ['type' => 'success', 'message' => $schedule->status === 'completed'
            ? 'Published. Your article is live.'
            : 'Sending your article to your website now.']);

        return back();
    }
}
