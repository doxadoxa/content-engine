<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Models\Project;
use Closure;
use Illuminate\Support\Facades\Context;

/**
 * The current tenant, for HTTP requests, console commands and queue workers alike.
 *
 * Backed by {@see Context} rather than a property on a singleton, because
 * Laravel serialises the context into every queued job payload and hydrates it
 * before the job runs. That is what makes a job dispatched while project A is
 * current see project A on the worker — with a singleton it would see whatever
 * the worker happened to be holding, which is the previous job's tenant.
 */
final class CurrentProject
{
    private const KEY = 'tenant.project_id';

    /**
     * The model behind the current id. The scope asks for the id on every
     * query; the panel and pipeline steps ask for the model, and without this
     * each of those is another SELECT for a row that has not changed.
     *
     * Deliberately one model rather than a map keyed by id. A Horizon worker
     * outlives thousands of jobs across every project, and a map would keep
     * serving each of them the Project as it looked the first time that worker
     * saw it — so pausing a project would take effect only after a restart.
     * See {@see flushResolved()}, which is wired to context hydration.
     */
    private ?Project $resolved = null;

    public function set(Project|string $project): void
    {
        if ($project instanceof Project) {
            $this->resolved = $project;
        } elseif ($this->resolved?->getKey() !== $project) {
            $this->resolved = null;
        }

        Context::add(self::KEY, $project instanceof Project ? $project->getKey() : $project);
    }

    public function id(): ?string
    {
        $id = Context::get(self::KEY);

        return is_string($id) ? $id : null;
    }

    public function get(): ?Project
    {
        $id = $this->id();

        if ($id === null) {
            return null;
        }

        if ($this->resolved?->getKey() !== $id) {
            $this->resolved = Project::withoutGlobalScopes()->findOrFail($id);
        }

        return $this->resolved;
    }

    public function isSet(): bool
    {
        return $this->id() !== null;
    }

    public function forget(): void
    {
        $this->resolved = null;

        Context::forget(self::KEY);
    }

    /**
     * Drop the resolved model, keeping the id.
     *
     * Called when the context is hydrated — that is, when a worker picks up a
     * job — so the first read of the project inside that job comes from the
     * database rather than from whatever the previous job left behind.
     */
    public function flushResolved(): void
    {
        $this->resolved = null;
    }

    /**
     * Run a callback with a different tenant current, then put the previous one
     * back — including when the callback throws, which is the case that leaks a
     * tenant into unrelated work if it is not handled.
     *
     * Do not return a PendingDispatch from the callback. It builds the job
     * payload in its destructor, which runs at the end of the *caller's*
     * statement — after the previous tenant has been restored, so the job is
     * queued under the wrong project. Dispatch inside a `void` closure instead.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function run(Project|string|null $project, Closure $callback): mixed
    {
        $previous = $this->id();

        $project === null ? $this->forget() : $this->set($project);

        try {
            return $callback();
        } finally {
            $previous === null ? $this->forget() : $this->set($previous);
        }
    }
}
