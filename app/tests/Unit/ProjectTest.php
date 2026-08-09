<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\ProjectStatus;
use App\Models\Project;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectTest extends TestCase
{
    #[Test]
    public function the_default_locale_is_always_supported(): void
    {
        // Even when someone saves the project with an empty locales list, its
        // own default has to stay publishable — otherwise the daily pipeline
        // has a project with nowhere to write.
        $project = new Project(['default_locale' => 'pt-PT', 'locales' => []]);

        $this->assertTrue($project->supportsLocale('pt-PT'));
    }

    #[Test]
    public function an_additional_locale_is_supported(): void
    {
        $project = new Project(['default_locale' => 'pt-PT', 'locales' => ['pt-PT', 'en']]);

        $this->assertTrue($project->supportsLocale('en'));
    }

    #[Test]
    public function an_unlisted_locale_is_not_supported(): void
    {
        $project = new Project(['default_locale' => 'pt-PT', 'locales' => ['pt-PT', 'en']]);

        $this->assertFalse($project->supportsLocale('de'));
    }

    #[Test]
    public function the_status_is_an_enum(): void
    {
        $project = new Project(['status' => 'paused']);

        $this->assertSame(ProjectStatus::Paused, $project->status);
    }
}
