<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Planning;

use App\Pipelines\Contracts\StepPayload;

/** The plan that came out, for whoever reads the run. */
final readonly class PlanPayload implements StepPayload
{
    /**
     * @param  list<array{id: string, locale: string, source_id: string}>  $variants
     *                                                                                the locale rows this run created, each with the unit it was copied
     *                                                                                from. Named rather than left to be inferred: {@see LocaliseVariants}
     *                                                                                has to tell a variant from the unit it came from, and every way of
     *                                                                                working that out after the fact — "the one with no search volume", "the
     *                                                                                one whose locale is not the project default" — is a guess that is wrong
     *                                                                                for some project. The step that made them knows.
     */
    public function __construct(
        public string $planId,
        public string $month,
        public int $units,
        public int $locales,
        public array $variants = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plan_id' => $this->planId,
            'month' => $this->month,
            'units' => $this->units,
            'locales' => $this->locales,
            'variants' => $this->variants,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            planId: (string) ($data['plan_id'] ?? ''),
            month: (string) ($data['month'] ?? ''),
            units: (int) ($data['units'] ?? 0),
            locales: (int) ($data['locales'] ?? 0),
            variants: self::variantsFrom($data['variants'] ?? []),
        );
    }

    /**
     * @return list<array{id: string, locale: string, source_id: string}>
     */
    private static function variantsFrom(mixed $variants): array
    {
        if (! is_array($variants)) {
            return [];
        }

        $clean = [];

        foreach ($variants as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $id = (string) ($variant['id'] ?? '');
            $locale = (string) ($variant['locale'] ?? '');
            $sourceId = (string) ($variant['source_id'] ?? '');

            if ($id === '' || $locale === '' || $sourceId === '') {
                continue;
            }

            $clean[] = ['id' => $id, 'locale' => $locale, 'source_id' => $sourceId];
        }

        return $clean;
    }
}
