<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Enums\ChannelType;
use App\Media\CarouselPanels;
use App\Media\PanelRenderer;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Support\Brand\VisualStyle;
use App\Support\Social\ChannelPlaybook;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/*
 * A disk that will not take a panel must not take the post with it.
 *
 * `generateIdea` persists every draft and *then* illustrates, holding a lock
 * rather than a transaction. So anything raised out of illustration leaves the
 * drafts written, and the retry finds nothing missing, answers `created: 0` and
 * never illustrates — the carousel has no pictures and nobody is told why. That
 * is not hypothetical: it is written on the renderer catch in CarouselPanels as
 * something that already happened once, to a refused connection.
 *
 * Raising on a refused write reintroduced it through a second door. These are
 * the assertions for the door.
 */
final class CarouselPanelWriteFailureTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_refused_panel_write_costs_the_panel_and_not_the_post(): void
    {
        $item = ContentItem::factory()->create();

        // Renders fine; the disk is what fails. Otherwise this would be testing
        // the renderer catch that already existed.
        $this->app->instance(PanelRenderer::class, new class extends PanelRenderer
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function render(string $composition, array $props, int $width, int $height): string
            {
                return 'png-bytes';
            }
        });

        config(['media.disk' => 'refusing']);
        $refusing = $this->createMock(Filesystem::class);
        $refusing->method('put')->willReturn(false);
        Storage::set('refusing', $refusing);

        $drawn = app(CarouselPanels::class)->draw(
            $item,
            ChannelPlaybook::for(ChannelType::Instagram),
            [
                ['heading' => 'One', 'body' => 'first', 'layout' => null, 'fields' => []],
                ['heading' => 'Two', 'body' => 'second', 'layout' => null, 'fields' => []],
            ],
            VisualStyle::fromBrief(null),
        );

        // Nothing raised, nothing drawn, and — the part that matters — no Asset
        // row describing a file the disk refused to store.
        $this->assertSame([], $drawn);
        $this->assertSame(0, Asset::query()->where('content_item_id', $item->getKey())->count());
    }
}
