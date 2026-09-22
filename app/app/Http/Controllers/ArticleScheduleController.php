<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ContentItem;
use App\Publishing\Articles\ArticleSchedules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class ArticleScheduleController extends Controller
{
    public function save(Request $request, ContentItem $item, ArticleSchedules $schedules): RedirectResponse
    {
        /** @var array{expected_version: int|null, local_date: string, local_time: string, mode: string, channel_id: string} $input */
        $input = $request->validate([
            'expected_version' => ['present', 'nullable', 'integer', 'min:1'],
            'local_date' => ['required', 'date_format:Y-m-d'], 'local_time' => ['required', 'date_format:H:i'],
            'mode' => ['required', 'in:automatic,review_first'], 'channel_id' => ['required', 'string'],
        ]);
        $actor = $request->user();
        abort_if($actor === null, 403);
        $schedules->save($actor, $item, $input);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Publication schedule saved.']);

        return back();
    }

    public function pause(Request $request, ContentItem $item, ArticleSchedules $schedules): RedirectResponse
    {
        return $this->change($request, $item, $schedules, 'pause');
    }

    public function resume(Request $request, ContentItem $item, ArticleSchedules $schedules): RedirectResponse
    {
        return $this->change($request, $item, $schedules, 'resume');
    }

    public function cancel(Request $request, ContentItem $item, ArticleSchedules $schedules): RedirectResponse
    {
        return $this->change($request, $item, $schedules, 'cancel');
    }

    private function change(Request $request, ContentItem $item, ArticleSchedules $schedules, string $action): RedirectResponse
    {
        $input = $request->validate(['expected_version' => ['required', 'integer', 'min:1']]);
        $schedules->change($item, (int) $input['expected_version'], $action);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Publication schedule updated.']);

        return back();
    }
}
