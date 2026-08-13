<?php

declare(strict_types=1);

namespace App\Pipelines\Definitions;

use App\Pipelines\Contracts\PipelineDefinition;
use App\Pipelines\Contracts\Step;
use App\Pipelines\Steps\Audit\WriteFixPlan;

/**
 * "What should I fix first?" — the model reading one sweep's findings.
 *
 * Its own pipeline rather than a seventh step of {@see SiteAuditPipeline}, for
 * two reasons that both come down to who is paying.
 *
 * It is a button, not a consequence. A sweep runs weekly for every project and
 * on every launch; a fix plan is asked for by somebody who has looked at the
 * findings and wants them ordered. Folding it into the sweep would buy a model
 * call a week per project for a document nobody opened.
 *
 * And §8 wants model spend readable per line, where a line is a pipeline key.
 * On the expensive queue rather than the audit one, because what it does is a
 * model call — the audit pool exists for work that is slow at the network, and
 * a long generation must not sit in front of a crawl any more than the reverse.
 */
class SiteAuditFixPlanPipeline implements PipelineDefinition
{
    public static function key(): string
    {
        return 'site_audit_fix_plan';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function name(): string
    {
        return 'Site audit fix plan';
    }

    /** @return list<class-string<Step>> */
    public function steps(): array
    {
        return [WriteFixPlan::class];
    }

    /** @return array<string, mixed> */
    public function inputRules(): array
    {
        return [
            'site_audit_id' => ['required', 'string', 'ulid'],
        ];
    }
}
