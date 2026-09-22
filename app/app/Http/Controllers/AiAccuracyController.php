<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiAccuracyAssessment;
use App\Models\AiAccuracyFinding;
use App\Models\AiCorrectionAction;
use App\Models\AiSamplingAnswer;
use App\Models\SitePage;
use App\Models\User;
use App\Support\Http\UnsafePublicUrl;
use App\Visibility\Accuracy\AccuracyAssessments;
use App\Visibility\Accuracy\CorrectionActions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class AiAccuracyController extends Controller
{
    public function assess(Request $request, AiSamplingAnswer $answer, AccuracyAssessments $assessments): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        $assessments->answer($this->actor($request), $answer, $data['request_key']);

        return to_route('ai-sampling.answer', $answer);
    }

    public function review(Request $request, AiAccuracyFinding $finding, AccuracyAssessments $assessments): RedirectResponse
    {
        $assessments->review($this->actor($request), $finding, $request->all());

        return $this->answer($finding);
    }

    public function inspectPage(Request $request, AiAccuracyFinding $finding, AccuracyAssessments $assessments): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid'], 'site_page_id' => ['required', 'ulid']]);
        try {
            $assessments->page($this->actor($request), $finding, SitePage::query()->whereKey($data['site_page_id'])->firstOrFail(), $data['request_key']);
        } catch (UnsafePublicUrl|ConnectionException|InvalidArgumentException $error) {
            throw ValidationException::withMessages(['site_page_id' => 'The selected page could not be safely captured. '.$error->getMessage()]);
        }

        return $this->answer($finding);
    }

    public function ownedPage(Request $request, AiAccuracyFinding $finding, CorrectionActions $actions): RedirectResponse
    {
        $action = $actions->ownedPage($this->actor($request), $finding);

        return $action->kind === 'owned_page' ? to_route('opportunities.index') : $this->answer($finding);
    }

    public function handoff(Request $request, AiAccuracyFinding $finding, CorrectionActions $actions): RedirectResponse
    {
        $actions->handoff($this->actor($request), $finding);

        return $this->answer($finding);
    }

    public function recordHandoff(Request $request, AiCorrectionAction $action, CorrectionActions $actions): RedirectResponse
    {
        $actions->recordHandoff($this->actor($request), $action, $request->all());

        return $this->answer(AiAccuracyFinding::query()->findOrFail($action->finding_id));
    }

    public function recheck(Request $request, AiCorrectionAction $action, CorrectionActions $actions): RedirectResponse
    {
        $data = $request->validate(['request_key' => ['required', 'uuid']]);
        $run = $actions->recheck($this->actor($request), $action, $data['request_key']);

        return to_route('ai-sampling.run', $run);
    }

    private function answer(AiAccuracyFinding $finding): RedirectResponse
    {
        $assessment = AiAccuracyAssessment::query()->findOrFail($finding->assessment_id);

        return to_route('ai-sampling.answer', $assessment->answer_id);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();

        return $actor instanceof User ? $actor : abort(403);
    }
}
