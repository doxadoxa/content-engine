<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialPlan;

use App\Pipelines\Contracts\StepPayload;

/** The week's row, as §7 will read it. */
final readonly class PlanRecordPayload implements StepPayload
{
    public function __construct(
        public string $planId,
        public int $planned,
        public ?string $alert,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['plan_id' => $this->planId, 'planned' => $this->planned, 'alert' => $this->alert];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): static
    {
        $alert = $data['alert'] ?? null;

        return new self(
            planId: (string) ($data['plan_id'] ?? ''),
            planned: (int) ($data['planned'] ?? 0),
            alert: is_string($alert) && $alert !== '' ? $alert : null,
        );
    }
}
