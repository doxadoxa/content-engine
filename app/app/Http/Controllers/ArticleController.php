<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ContentItemType;
use App\Models\ContentItem;
use App\Pipelines\Core\PipelineRunner;
use App\Support\Engine\MonthPlanner;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Throwable;

/**
 * The article half's two operator verbs.
 *
 * It had none. Every article in this engine came from a `planning` run that
 * chooses a month of topics out of keyword research — and that run fires once,
 * inside `ProjectLaunch`, and is on no schedule and no button afterwards. So a
 * project whose first month was planned in August had, by construction, no way
 * to ask for a second one, and no way to ask for a single article about the
 * thing that happened this morning. The half that only ever acts on its own is
 * the half most able to stop without anybody noticing, and on the project this
 * was written against it had: forty-five ideas, fifty-two approved, nothing
 * published, and the last planning run seventeen days earlier.
 *
 * Both actions are the same divergence {@see ContentStudioController::storeIdea()}
 * already makes for the social half — a person may put a unit into the engine
 * without the planner's permission — and they are held to the same rule it is:
 * the engine still writes it, a person still approves it, nothing publishes.
 */
class ArticleController extends Controller
{
    public function __construct(
        private readonly CurrentProject $current,
        private readonly PipelineRunner $runner,
        private readonly MonthPlanner $planner,
    ) {}

    /**
     * One article, from a sentence.
     *
     * The typed sentence becomes the `target_query`, which is the field
     * `CompileBrief` reads and the whole brief is built from. A hand-typed
     * query skips the volume data a researched one carries — that is the
     * trade, and it is the operator's to make: the thing a customer asked this
     * morning is worth writing about whether or not a keyword tool has heard
     * of it.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $project = $this->current->get();

        if ($project === null) {
            return $this->say('error', 'Choose a project first.');
        }

        $query = trim($validated['prompt']);
        $title = Str::ucfirst($query);

        // `state` is not fillable and is not set here: the column defaults to
        // `idea`, and the state machine owns every move out of it. Assigning it
        // by hand would be the one write that did not go through the machine.
        $item = ContentItem::query()->create([
            'locale' => $project->default_locale,
            // The planner picks a shape per topic from the research; a typed
            // one has nothing to pick from, so it gets the plainest of them and
            // the unit card is where it gets changed. Guessing a comparison or
            // a listicle out of one sentence would be a worse default than the
            // honest one.
            'type' => ContentItemType::Explainer,
            'slug' => Str::slug(Str::limit($query, 60, '')).'-'.Str::lower(Str::random(4)),
            'title' => $title,
            'target_query' => $query,
        ]);

        try {
            $this->runner->start('generation', $project, [], $item->getKey());
        } catch (Throwable) {
            $item->delete();

            return $this->say('error', 'The engine could not start writing that. Try again in a moment.');
        }

        return $this->say('success', 'Writing it now — it will appear in the content plan.');
    }

    /**
     * A month of articles, chosen from the research.
     *
     * Refused rather than queued while one is in flight — see
     * {@see MonthPlanner}, which holds the lock this check has to happen under
     * and which the assistant's `plan_month` tool shares.
     */
    public function plan(): RedirectResponse
    {
        $project = $this->current->get();

        if ($project === null) {
            return $this->say('error', 'Choose a project first.');
        }

        try {
            $run = $this->planner->start($project);
        } catch (Throwable) {
            return $this->say('error', 'The planner could not start. Try again in a moment.');
        }

        return $run === null
            ? $this->say('info', 'A month is already being planned.')
            : $this->say('success', 'Planning the next month. This takes a few minutes.');
    }

    /**
     * Say something to the person who pressed the button.
     *
     * Through `Inertia::flash`, which is what this app's toast actually reads —
     * a plain session flash is shared by nothing and rendered by nothing, so
     * every message here went to the floor. Pressing "Plan next month" while
     * one was already running looked exactly like pressing a dead button.
     */
    private function say(string $type, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
