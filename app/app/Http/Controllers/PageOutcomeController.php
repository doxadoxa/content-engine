<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PagePublication;
use App\Models\User;
use App\Opportunities\PageOutcomeReviews;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class PageOutcomeController extends Controller
{
    public function store(Request $request, PagePublication $publication, PageOutcomeReviews $outcomes): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:keep_observing,leave_unchanged,reassess'], 'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'expected_outcome_id' => ['present', 'nullable', 'ulid'], 'measurement_review_id' => ['present', 'nullable', 'ulid']]);
        $actor = $request->user();
        assert($actor instanceof User);
        try {
            $outcome = $outcomes->record($publication, $actor, $data);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['outcome' => 'The public page could not be reached. Retry when the website is available; no new cycle was created.']);
        } catch (UnsafePublicUrl $exception) {
            throw ValidationException::withMessages(['outcome' => $exception->getMessage()]);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }
            throw ValidationException::withMessages(['outcome' => $exception->getMessage()]);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $outcome->evidence['result']]);

        return back();
    }
}
