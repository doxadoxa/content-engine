<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditSeverity;
use App\Models\SiteAudit;
use App\Models\SiteAuditIssue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteAuditIssue>
 */
class SiteAuditIssueFactory extends Factory
{
    protected $model = SiteAuditIssue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_audit_id' => SiteAudit::factory(),
            'site_audit_page_id' => null,
            'check_key' => 'meta_description',
            'severity' => AuditSeverity::Medium,
            'summary' => 'This page has no meta description.',
            'detail' => [],
        ];
    }

    public function severity(AuditSeverity $severity): static
    {
        return $this->state(fn (): array => ['severity' => $severity]);
    }

    public function forCheck(string $key): static
    {
        return $this->state(fn (): array => ['check_key' => $key]);
    }
}
