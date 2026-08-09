<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Pipelines\Events\PipelineRunFinished;
use App\Providers\AppServiceProvider;

/**
 * Chains the onboarding pipelines. Does nothing for a project that is past its
 * launch, which is every project most of the time.
 *
 * Deliberately not in `app/Listeners`. Laravel discovers listeners there by
 * scanning for a `handle()` with an event type-hint, and this one is *also*
 * registered by hand in {@see AppServiceProvider} — two
 * registrations, so every finished run advanced the chain twice and one
 * completed research run started two planning runs. Living here, the explicit
 * registration is the only one, and it stays the readable answer to "what runs
 * when a pipeline finishes".
 */
class AdvanceProjectLaunch
{
    public function __construct(private readonly ProjectLaunch $launch) {}

    public function handle(PipelineRunFinished $event): void
    {
        $this->launch->advance($event->run);
    }
}
