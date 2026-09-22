<?php

declare(strict_types=1);

namespace App\Publishing\Pages;

use App\Models\PageSnapshot;
use App\Models\SitePage;
use App\Support\Http\UnsafePublicUrl;

final class NativePatchCompiler
{
    public function __construct(private readonly PageReceiverClient $client, private readonly WordPressPatchCompiler $wordpress) {}

    /** @param list<array<string,mixed>> $changes
     * @return array{editable_snapshot_id:string|null,compiled_patch:array<string,mixed>}
     */
    public function compile(SitePage $page, PageSnapshot $public, ?PageSnapshot $editable, array $changes): array
    {
        try {
            if ($editable === null || $page->channel === null) {
                throw new UnsupportedPageChange('No editable source was pinned before this revision. Bind the page and request a fresh revision, or use assistance.');
            }
            $destination = $this->client->destination($page->channel, $page);
            if (! PageReceiverClient::same($editable->metadata['destination'] ?? [], $destination) || $editable->captured_at->lessThan(now()->subDays(30))) {
                throw new UnsupportedPageChange('Capture current editable source for this destination before preparing another revision.');
            }
            $renderedChanges = [];
            $codecChanges = $changes;
            foreach ($changes as $change) {
                if ($change['kind'] !== 'title' || ($destination['type'] !== 'wordpress' && ($editable->metadata['title_affects_heading'] ?? false) !== true)) {
                    continue;
                }
                $storedBefore = (string) ($editable->fields['title'] ?? '');
                $storedAfter = WordPressPatchCompiler::untemplate($change['before'], $change['after'], $storedBefore);
                $effects = app(RenderedTitleChanges::class)->forTitle($public, $storedBefore, $storedAfter, $destination['type'] === 'wordpress');
                foreach ($effects as $effect) {
                    $existing = collect($changes)->firstWhere('locator', $effect['locator']);
                    if ($existing !== null) {
                        if ($existing['kind'] !== 'text_section' || $existing['operation'] !== 'replace' || $existing['after'] !== $effect['after']) {
                            throw new UnsupportedPageChange('The title and a separate visible title edit disagree. Resolve them in one reviewed revision.');
                        }
                        // The raw title patch already produces this effect; never also
                        // try to rewrite a theme heading in post_content.
                        $codecChanges = array_values(array_filter($codecChanges, static fn (array $item): bool => $item['locator'] !== $effect['locator']));
                    } else {
                        $renderedChanges[] = $effect;
                    }
                }
            }
            $patches = $destination['type'] === 'wordpress'
                ? $this->wordpress->compile($public, $editable, $codecChanges)
                : app(CustomPatchCompiler::class)->compile($public, $editable, $codecChanges);
            if ($patches === []) {
                throw new UnsupportedPageChange('There is no approved change to publish.');
            }
            if (count($changes) + count($renderedChanges) > 5) {
                throw new UnsupportedPageChange('Keep this revision within five visible edits, including the page heading coupled to a title change.');
            }

            return ['editable_snapshot_id' => $editable->id, 'compiled_patch' => ['status' => 'supported', 'destination' => $destination, 'expected_revision' => $editable->revision, 'patches' => $patches, 'public_snapshot_id' => $public->id, 'rendered_changes' => $renderedChanges, 'title_semantics' => $editable->metadata['title_semantics'] ?? null, 'description_semantics' => $editable->metadata['description_semantics'] ?? null]];
        } catch (UnsupportedPageChange|UnsafePublicUrl $exception) {
            return ['editable_snapshot_id' => $editable?->id, 'compiled_patch' => ['status' => 'assisted', 'reason' => $exception->getMessage()]];
        }
    }
}
