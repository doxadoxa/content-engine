<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SiteAudit;
use App\Models\SiteAuditPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SiteAuditPage>
 */
class SiteAuditPageFactory extends Factory
{
    protected $model = SiteAuditPage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = $this->faker->sentence(5);

        return [
            'site_audit_id' => SiteAudit::factory(),
            'url' => 'https://example.com/'.Str::slug($title),
            'status_code' => 200,
            'score' => 92,
            'issues_count' => 1,
            'response_ms' => 240,
            'html_bytes' => 48_000,
            'facts' => [
                'title' => $title,
                'description' => $this->faker->sentence(14),
                'canonical' => null,
                'headings' => [['level' => 1, 'text' => $title]],
                'json_ld_types' => ['Article'],
                'images' => [],
                'internal_links' => [],
                'has_viewport' => true,
                'lang' => 'en',
            ],
            'speed' => null,
        ];
    }
}
