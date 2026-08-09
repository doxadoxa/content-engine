<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialListen;

use App\Pipelines\Contracts\StepPayload;

/**
 * The reverse flow of §1.3, as a list (§12, exit criterion 1).
 *
 * "Статья ранжируется и цитируется, Threads находит, о чём её писать." A
 * listening run that publishes nothing and produces one row here has paid for
 * itself, which is the claim §4.1 makes and the number that settles it.
 */
final readonly class PlannedIdeasPayload implements StepPayload
{
    /**
     * @param  list<string>  $created  content unit ids, each carrying its signal_id
     * @param  array<string, string>  $skipped  question => why the planner did not take it
     */
    public function __construct(
        public array $created,
        public array $skipped,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['created' => $this->created, 'skipped' => $this->skipped];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        /** @var array<string, string> $skipped */
        $skipped = array_map(strval(...), is_array($data['skipped'] ?? null) ? $data['skipped'] : []);

        return new self(
            created: array_values(array_map(strval(...), is_array($data['created'] ?? null) ? $data['created'] : [])),
            skipped: $skipped,
        );
    }
}
