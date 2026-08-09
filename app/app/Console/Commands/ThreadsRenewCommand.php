<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\SkipsWhenSocialPresenceIsOff;
use App\Enums\IntegrationProvider;
use App\Integrations\Threads\ThreadsConnection;
use App\Models\Project;
use App\Models\ProjectIntegration;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `php artisan threads:renew` — keep every Threads token alive (§9).
 *
 * Until this existed, {@see ThreadsConnection::accessToken()} had exactly one
 * caller: the publisher. A ~60-day credential was therefore renewed only as a
 * side effect of trying to post, which quietly made "publishes at least once a
 * month" a condition of staying connected.
 *
 * That is the wrong condition twice over. A project can go two months without
 * an approved post and still be perfectly healthy. And §4.1 is explicit that
 * the listening contour is the one that pays for itself even if nothing is ever
 * published — so the project most likely to lose its token this way is the one
 * getting the most out of the connection.
 *
 * The renewal logic itself is not here. This walks projects and calls
 * `accessToken()`, which already knows the window: it renews inside it, does
 * nothing outside it, and refuses a token less than a day old because the
 * platform does. Duplicating any of that here would be a second opinion about
 * when a token is due, and the two would disagree.
 *
 * Every project with a connection, not only the active ones. A paused project's
 * token expiring would turn "unpause" into "reconnect", and the cost of keeping
 * it is one request every seven weeks.
 */
class ThreadsRenewCommand extends Command
{
    use SkipsWhenSocialPresenceIsOff;

    protected $signature = 'threads:renew
        {project? : Project slug or id. Omitted, every project with a Threads connection.}';

    protected $description = 'Renew Threads long-lived tokens before their ~60 days run out';

    public function handle(ThreadsConnection $connection, CurrentProject $current): int
    {
        if ($this->socialPresenceIsOff()) {
            return self::SUCCESS;
        }

        $handle = $this->argument('project');

        if (is_string($handle) && $handle !== '') {
            $project = Project::query()->where('slug', $handle)->first()
                ?? Project::query()->whereKey($handle)->first();

            if ($project === null) {
                $this->components->error('No project with that slug or id.');

                return self::FAILURE;
            }

            $this->renew($project, $connection, $current);

            return self::SUCCESS;
        }

        $projects = Project::query()->whereIn('id', $this->connected())->orderBy('name')->get();

        if ($projects->isEmpty()) {
            $this->components->info('No project has a Threads connection.');

            return self::SUCCESS;
        }

        foreach ($projects as $project) {
            $this->renew($project, $connection, $current);
        }

        return self::SUCCESS;
    }

    /**
     * The projects worth visiting, found in one query rather than by asking
     * every project whether it happens to have one.
     *
     * `acrossProjects()` because this is one of the few legitimately
     * cross-tenant reads the trait names — a maintenance command has no current
     * project of its own.
     *
     * @return list<string>
     */
    private function connected(): array
    {
        return array_values(array_map(strval(...), ProjectIntegration::acrossProjects()
            ->where('provider', IntegrationProvider::Threads->value)
            ->pluck('project_id')
            ->all()));
    }

    private function renew(Project $project, ThreadsConnection $connection, CurrentProject $current): void
    {
        $current->run($project, function () use ($project, $connection): void {
            $integration = $connection->for();

            if ($integration === null) {
                // Named rather than skipped silently, because the one time
                // somebody runs this with a project argument is when they
                // expected that project to have a connection.
                $this->components->twoColumnDetail($project->slug, 'no Threads connection');

                return;
            }

            if (! $connection->isUsable($integration)) {
                $this->broken($project, $integration, 'was already broken');

                return;
            }

            $before = $integration->access_token_expires_at;

            $connection->accessToken($integration);

            // Read back rather than trusted: `accessToken()` renews through a
            // `forceFill()->save()`, and marking a connection broken is one of
            // the things it may have done on the way.
            $after = $integration->fresh();

            if ($after === null || ! $connection->isUsable($after)) {
                $this->broken($project, $after ?? $integration, 'expired before it could be renewed');

                return;
            }

            $expiry = $after->access_token_expires_at;
            $renewed = $expiry !== null && ($before === null || ! $before->equalTo($expiry));

            $this->components->twoColumnDetail(
                $project->slug,
                ($renewed ? 'renewed, now expires ' : 'still fresh, expires ')
                    .($expiry?->toDateString() ?? 'unknown'),
            );
        });
    }

    /**
     * A dead token, said in both places.
     *
     * The console line is for whoever ran it by hand; the log line is the point
     * of the scheduled run. §9 puts the token on the integration precisely so a
     * broken connection has somewhere to live, and a warning here is what makes
     * it visible before somebody notices the posts stopped.
     */
    private function broken(Project $project, ProjectIntegration $integration, string $what): void
    {
        $this->components->warn("{$project->slug}: the Threads connection {$what} — reconnect to grant access again.");

        Log::warning('A Threads connection needs reconnecting', [
            'project' => $project->slug,
            'integration' => $integration->getKey(),
            'reason' => $integration->failure_reason,
        ]);
    }
}
