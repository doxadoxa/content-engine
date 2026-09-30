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
        $input = $request->validate([
            'expected_version' => ['present', 'nullable', 'integer', 'min:1'],
            'local_date' => ['required', 'date_format:Y-m-d'], 'local_time' => ['required', 'date_format:H:i'],
            // Optional both: one usable website needs no choosing, and a
            // review-first project has no hold to offer (see save()).
            'channel_id' => ['sometimes', 'nullable', 'string'], 'hold' => ['sometimes', 'nullable', 'boolean'],
        ]);
        $actor = $request->user();
        abort_if($actor === null, 403);
        $schedules->save($actor, $item, [
            'expected_version' => $input['expected_version'] === null ? null : (int) $input['expected_version'],
            'local_date' => (string) $input['local_date'], 'local_time' => (string) $input['local_time'],
            'channel_id' => isset($input['channel_id']) ? (string) $input['channel_id'] : null,
            'hold' => isset($input['hold']) ? $request->boolean('hold') : null,
        ]);
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
