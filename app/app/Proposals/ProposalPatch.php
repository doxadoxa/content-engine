<?php

declare(strict_types=1);

namespace App\Proposals;

use App\Models\BusinessFactVersion;
use App\Models\PageSnapshot;
use App\Models\SitePage;
use App\Pages\PageUrl;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProposalPatch
{
    public function __construct(private readonly PageBlocks $blocks) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowedFacts
     * @return array{changes: list<array<string, mixed>>, missing_facts: list<string>, no_change_reason?: string|null}
     */
    public function validate(PageSnapshot $snapshot, array $input, array $allowedFacts): array
    {
        $data = Validator::make($input, [
            'changes' => ['present', 'array', 'max:5'],
            'changes.*' => ['array:kind,operation,locator,before,after,reason,fact_version_ids,target_page_id,anchor_text'],
            'changes.*.kind' => ['required', Rule::in(['title', 'description', 'text_section', 'internal_link'])],
            'changes.*.operation' => ['required', Rule::in(['replace', 'insert_after'])],
            'changes.*.locator' => ['required', 'string', 'max:100'],
            'changes.*.before' => ['present', 'nullable', 'string', 'max:2400'],
            'changes.*.after' => ['required', 'string', 'max:2400'],
            'changes.*.reason' => ['required', 'string', 'max:1000'],
            'changes.*.fact_version_ids' => ['present', 'array', 'max:20'],
            'changes.*.fact_version_ids.*' => ['required', 'ulid', Rule::in($allowedFacts)],
            'changes.*.target_page_id' => ['nullable', 'ulid'],
            'changes.*.anchor_text' => ['nullable', 'string', 'max:150'],
            'missing_facts' => ['present', 'array', 'max:10'],
            'missing_facts.*' => ['required', 'string', 'max:500'],
            'no_change_reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();
        $blocks = collect($this->blocks->from($snapshot))->keyBy('id');
        $seen = [];
        $total = 0;
        foreach ($data['changes'] as &$change) {
            $change['before'] ??= ''; // Empty metadata is a supported original value after HTTP normalization.
            $locator = $change['locator'];
            $kind = $change['kind'];
            if ($kind !== 'internal_link' && $change['fact_version_ids'] === []) {
                $this->reject('Every text change needs at least one current owner-confirmed supporting fact.');
            }
            if (isset($seen[$locator])) {
                $this->reject('Each field or block may be changed only once in a proposal.');
            }
            $seen[$locator] = true;
            $after = $change['after'];
            if ($after !== strip_tags($after) || preg_match('/[<>\x00-\x08]/u', $after)) {
                $this->reject('Use plain text, not HTML, scripts or template code.');
            }
            $total += mb_strlen($after);
            if ($total > 6000) {
                $this->reject('Keep this proposal to a few focused changes.');
            }
            if (in_array($kind, ['title', 'description'], true)) {
                if ($locator !== $kind || $change['operation'] !== 'replace' || $change['before'] !== ($snapshot->fields[$kind] ?? '') || mb_strlen($after) > ($kind === 'title' ? 200 : 320)) {
                    $this->reject('Metadata changes must match the captured field and stay within its supported length.');
                }
            } else {
                $block = $blocks->get($locator);
                if ($block === null || $block['text'] !== $change['before'] || $blocks->where('text', $change['before'])->count() !== 1) {
                    $this->reject('The selected source block is missing or ambiguous. Capture and review a new snapshot.');
                }
                if ($kind === 'text_section' && $change['operation'] === 'replace' && $block['links'] !== []) {
                    $this->reject('This block contains links. Use a separate internal-link change or choose a text block without links.');
                }
                if ($kind === 'internal_link') {
                    $target = SitePage::query()->tracked()->find((string) ($change['target_page_id'] ?? ''));
                    $anchor = $change['anchor_text'] ?? '';
                    if ($change['operation'] !== 'replace' || $change['after'] !== $change['before'] || $anchor === '' || substr_count($change['before'], $anchor) !== 1 || $block['links'] !== [] || $target === null || $target->id === $snapshot->site_page_id) {
                        $this->reject('Choose one existing phrase in an unlinked block and another tracked target page.');
                    }
                    $sourceUrl = (string) ($snapshot->metadata['canonical_url'] ?? $snapshot->source_url);
                    if (parse_url((string) $target->canonical_url, PHP_URL_HOST) !== parse_url($sourceUrl, PHP_URL_HOST) || $target->locale !== ($snapshot->metadata['locale'] ?? null)) {
                        $this->reject('Internal links must use a tracked page on the same site and in the same language.');
                    }
                    $change['target_url'] = PageUrl::normalize((string) $target->canonical_url);
                }
            }
            if ($kind !== 'internal_link' && $change['operation'] === 'replace' && PageBlocks::normalize($change['before']) === PageBlocks::normalize($after)) {
                $this->reject('A proposal must contain an actual change.');
            }
            foreach ($change['fact_version_ids'] as $id) {
                $fact = BusinessFactVersion::query()->with('fact')->find((string) $id);
                if ($fact === null || ! $fact->isUsable() || $fact->fact?->current_version_id !== $id) {
                    $this->reject('A supporting fact changed or needs confirmation. Review its current version first.');
                }
            }
        }
        unset($change);
        if ($data['changes'] === [] && $data['missing_facts'] === [] && empty($data['no_change_reason'])) {
            $this->reject('Explain why no justified change was found, or identify the missing evidence.');
        }

        /** @var array{changes: list<array<string, mixed>>, missing_facts: list<string>, no_change_reason?: string|null} $data */
        return $data;
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['changes' => $message]);
    }
}
