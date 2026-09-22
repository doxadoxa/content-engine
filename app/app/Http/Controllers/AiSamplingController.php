<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Models\Project;
use App\Models\User;
use App\Support\Tenancy\CurrentProject;
use App\Visibility\Accuracy\AccuracyReport;
use App\Visibility\Sampling\SamplingReport;
use App\Visibility\Sampling\SamplingRuns;
use App\Visibility\Sampling\SamplingSchedule;
use App\Visibility\Sampling\SamplingSets;
use App\Visibility\Sampling\SamplingTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class AiSamplingController extends Controller
{
    public function __construct(private readonly CurrentProject $current) {}

    public function set(Request $request, SamplingSets $sets): RedirectResponse
    {
        $sets->save($this->project(), $this->actor($request), $request->all());

        return back();
    }

    public function sample(Request $request, SamplingRuns $runs): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'set_id' => ['required', 'ulid']]);
        $set = AiSamplingSet::query()->whereKey($data['set_id'])->firstOrFail();
        $run = $runs->start($this->project(), $set, $data['request_key'], $this->actor($request));

        return to_route('ai-sampling.run', $run);
    }

    public function run(AiSamplingRun $run, SamplingReport $report): Response
    {
        return Inertia::render('visibility/index', ['sampling' => $report->get($run), 'legacy' => null]);
    }

    public function answer(AiSamplingAnswer $answer): Response
    {
        $cell = AiSamplingCell::query()->whereKey($answer->sampling_cell_id)->firstOrFail();

        return Inertia::render('visibility/answer', ['answer' => $answer, 'cell' => $cell, 'allowance' => app(SamplingSchedule::class)->describe($this->project()), 'accuracy' => app(AccuracyReport::class)->answer($answer)]);
    }

    public function recheck(Request $request, AiSamplingAnswer $answer, SamplingRuns $runs): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        $cell = AiSamplingCell::query()->whereKey($answer->sampling_cell_id)->firstOrFail();
        $previous = AiSamplingRun::query()->whereKey($cell->sampling_run_id)->firstOrFail();
        $set = AiSamplingSet::query()->whereKey($previous->sampling_set_id)->firstOrFail();
        $run = $runs->start($this->project(), $set, $data['request_key'], $this->actor($request), $previous, [$cell->cell_key]);

        return to_route('ai-sampling.run', $run);
    }

    public function recheckCell(Request $request, AiSamplingCell $cell, SamplingRuns $runs): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        abort_if(in_array($cell->status, ['queued', 'running'], true) && ! SamplingTiming::isStale($cell->attempted_at), 409, 'This question is still being collected.');
        $previous = AiSamplingRun::query()->whereKey($cell->sampling_run_id)->firstOrFail();
        $set = AiSamplingSet::query()->whereKey($previous->sampling_set_id)->firstOrFail();
        $run = $runs->start($this->project(), $set, $data['request_key'], $this->actor($request), $previous, [$cell->cell_key]);

        return to_route('ai-sampling.run', $run);
    }

    private function project(): Project
    {
        return $this->current->get() ?? abort(404);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->projects()->whereKey($this->project()->id)->wherePivot('role', 'owner')->exists(), 403);

        return $actor;
    }
}
