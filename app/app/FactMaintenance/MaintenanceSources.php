<?php

declare(strict_types=1);

namespace App\FactMaintenance;

use App\Facts\FactSection;
use App\Models\PageSnapshot;

/** Reassemble overlapping captured surfaces; never silently count overlap as additional coverage. */
final class MaintenanceSources
{
    /** @return array{sections:list<FactSection>,surfaces:array<string,array<string,mixed>>,coverage:array<string,mixed>} */
    public function from(PageSnapshot $snapshot, string $business): array
    {
        $capture = $snapshot->metadata['fact_surfaces'] ?? [];
        $surfaces = [];
        $omissions = $capture['omitted'] ?? [];
        foreach ($capture['surfaces'] ?? [] as $surface) {
            $key = $surface['kind'].':'.hash('sha256', $surface['locator']);
            $surfaces[$key] ??= ['kind' => $surface['kind'], 'locator' => $surface['locator'], 'format' => $surface['format'], 'text' => '', 'total_characters' => $surface['total_characters']];
            $current = $surfaces[$key]['text'];
            $offset = (int) $surface['offset'];
            $overlap = mb_strlen($current) - $offset;
            if ($overlap < 0 || ($overlap > 0 && mb_substr($current, $offset, $overlap) !== mb_substr($surface['text'], 0, $overlap))) {
                $omissions[] = ['locator' => $surface['locator'], 'reason' => 'Captured sections contain a gap or disagree. This surface is incomplete.'];

                continue;
            }
            $surfaces[$key]['text'] .= mb_substr($surface['text'], $overlap);
        }
        $sections = [];
        foreach ($surfaces as $key => $surface) {
            $sections[] = new FactSection($key, $surface['text'], ['business' => $business, 'url' => $snapshot->source_url,
                'locale' => $snapshot->metadata['locale'] ?? null, 'surface_kind' => $surface['kind'], 'source_locator' => $surface['locator'], 'format' => $surface['format']]);
            if (mb_strlen($surface['text']) < $surface['total_characters']) {
                $omissions[] = ['locator' => $surface['locator'], 'reason' => 'Part of this source was omitted from capture.'];
            }
        }
        if ($sections === []) {
            $omissions[] = ['reason' => 'This snapshot predates full factual-surface capture or contains no assessable text. Capture the page again.'];
        }

        return ['sections' => $sections, 'surfaces' => $surfaces, 'coverage' => ['capture_status' => $omissions === [] ? 'captured' : 'partial',
            'captured_characters' => array_sum(array_map(fn (FactSection $section): int => mb_strlen($section->text), $sections)),
            'omitted' => $omissions, 'limitations' => $capture['limitations'] ?? [],
            'applicability' => 'Only the selected confirmed facts and captured delivered text are checked. Other facts, rendered script content, images and omitted surfaces remain unknown.']];
    }
}
