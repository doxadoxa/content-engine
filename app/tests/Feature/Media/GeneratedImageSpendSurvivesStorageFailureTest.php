<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Media\AtlasSeedreamImageGeneration;
use App\Media\MediaWriteFailed;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/*
 * A picture that was paid for and then not stored still has to be paid for.
 *
 * The provider draws, the engine downloads, and only then does anything reach
 * the disk — so a refused write happens strictly after the money has gone. The
 * cost travels inside GeneratedImage, which on that path is never constructed,
 * so raising on the write took the bill with it: real spend, no cost row, and
 * a caller that retries pays a second time for the same picture.
 *
 * §6's reports are the only place a storage outage would show up as money, so
 * this is what keeps the failure expensive on paper as well as in fact.
 */
final class GeneratedImageSpendSurvivesStorageFailureTest extends TestCase
{
    #[Test]
    public function a_write_that_fails_after_generation_carries_the_bill_out_with_it(): void
    {
        config([
            'media.atlas.api_key' => 'test-key',
            'media.atlas.base_url' => 'https://atlas.test',
            // Both, because which one is used depends on whether references
            // were passed, and this test is not about that choice.
            'media.atlas.model' => 'seedream-test',
            'media.atlas.text_model' => 'seedream-test',
            'media.atlas.cost_micros' => 40_000,
            'media.disk' => 'refusing',
        ]);

        Http::fake([
            'atlas.test/model/generateImage' => Http::response(['data' => ['id' => 'op-1']]),
            'atlas.test/model/prediction/*' => Http::response([
                'data' => ['status' => 'succeeded', 'outputs' => ['https://pictures.test/x.webp']],
            ]),
            'pictures.test/*' => Http::response('webp-bytes'),
        ]);

        $refusing = $this->createMock(Filesystem::class);
        $refusing->method('put')->willReturn(false);
        Storage::set('refusing', $refusing);

        try {
            app(AtlasSeedreamImageGeneration::class)->generate('a lighthouse');
            $this->fail('The refused write should have raised.');
        } catch (MediaWriteFailed $e) {
            $this->assertTrue($e->wasPaidFor(), 'The failure lost the cost of the picture.');
            $this->assertSame(40_000, $e->spendMicros);
            $this->assertSame('seedream-test', $e->spendModel);
            $this->assertNotNull($e->spendProvider);
        }
    }
}
