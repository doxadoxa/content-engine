<?php

declare(strict_types=1);

namespace App\Visibility;

use App\Models\AiSamplingAnswer;
use App\Models\AiSamplingCell;
use App\Models\AiSamplingRun;
use App\Models\AiSamplingSet;
use App\Visibility\Sampling\SamplingReport;

/** Read stored discovery results without buying answers or mixing reporting methods. */
final class DashboardVisibility
{
    public function __construct(private readonly SamplingReport $sampling) {}

    /** @return array<string, mixed> */
    public function get(): array
    {
        $discovery = AiSamplingCell::query()->where('specification->prompt->purpose', 'discovery');
        // A one-question recheck is evidence for that question, not a replacement for the whole panel.
        $runs = AiSamplingRun::query()->whereNull('recheck_of_run_id')
            ->whereIn('id', (clone $discovery)->select('sampling_run_id'));
        $latest = (clone $runs)->orderByDesc('id')->first();
        $measured = (clone $runs)->whereIn('id', (clone $discovery)->where('status', 'answered')
            ->whereIn('id', AiSamplingAnswer::query()->select('sampling_cell_id'))->select('sampling_run_id'))
            ->orderByDesc('id')->first();
        $selected = $measured ?? $latest;
        $sample = $selected === null ? null : $this->sampling->run($selected);
        $discoveryCells = collect($sample['cells'] ?? [])->filter(fn (array $cell): bool => $cell['specification']['prompt']['purpose'] === 'discovery');
        $platforms = array_values(array_unique([...array_keys(config('visibility.platforms', [])),
            ...$discoveryCells->pluck('specification.platform.platform')->all()]));
        $providers = [];
        foreach ($platforms as $platform) {
            $cells = $discoveryCells->filter(fn (array $cell): bool => $cell['specification']['platform']['platform'] === $platform);
            $answers = $cells->filter(fn (array $cell): bool => $cell['status'] === 'answered' && $cell['answer'] !== null);
            $mentions = $answers->where('answer.mentioned_in_text', true)->count();
            $providers[] = ['platform' => $platform, 'label' => config('visibility.platforms.'.$platform.'.label', $platform),
                'answers' => $answers->count(), 'expected' => $cells->count(),
                'mentions' => $answers->isEmpty() ? null : $mentions,
                'score' => $answers->isEmpty() ? null : round($mentions / $answers->count() * 100, 1),
                'sampled_at' => $answers->pluck('answer.received_at')->filter()->max()];
        }
        $legacy = VisibilityReport::latest();
        $answered = $sample['discovery']['answered'] ?? 0;

        return [
            'ai' => [
                'status' => $sample['status'] ?? 'not_sampled', 'run_id' => $selected?->id,
                'sampled_at' => $sample['finished_at'] ?? $sample['created_at'] ?? null,
                'answers' => $answered, 'expected' => $discoveryCells->count(),
                'mentions' => $answered > 0 ? $sample['discovery']['mentions'] : null,
                'citations' => $answered > 0 ? $sample['discovery']['citations'] : null,
                'score' => $sample['discovery']['mention_rate'] ?? null,
                'providers' => $providers, 'has_questions' => AiSamplingSet::query()->exists(),
                'latest_attempt' => $latest !== null && $latest->id !== $selected?->id
                    ? ['status' => $latest->status, 'created_at' => $latest->created_at->toIso8601String()] : null,
            ],
            'earlier_ai' => $legacy->answered() === 0 ? null : [
                'score' => $legacy->score(), 'answers' => $legacy->answered(), 'mentions' => $legacy->mentions(),
                'sampled_at' => $legacy->lastAskedOn?->toDateString(),
                'providers' => array_map(static fn (array $row): array => [...$row,
                    'label' => config('visibility.platforms.'.$row['platform'].'.label', $row['platform'])], $legacy->byPlatform()),
            ],
        ];
    }
}
