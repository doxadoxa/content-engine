<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\SearchIntent;
use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Steps\Planning\TypeAndFlagUnits;
use App\Pipelines\Steps\Research\ClusterByIntent;
use App\Research\AhrefsKeywordSource;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The parsing and classification that phase 4 leans on, with no pipeline around
 * it.
 *
 * The Ahrefs adapter is never reached by a pipeline test — the suite binds the
 * fake — so without this its response mapping would ship entirely unexercised.
 * `Http::fake` is not the network: it is the response shape, asserted.
 */
final class KeywordSourceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, SearchIntent}>
     */
    public static function queries(): iterable
    {
        yield 'how to' => ['how to clean windows', SearchIntent::Informational];
        yield 'bare noun' => ['window cleaning', SearchIntent::Informational];
        yield 'best' => ['best window cleaner', SearchIntent::Commercial];
        yield 'vs' => ['helpling vs zaask', SearchIntent::Commercial];
        yield 'cost' => ['window cleaning cost', SearchIntent::Transactional];
        yield 'near me' => ['window cleaner near me', SearchIntent::Navigational];

        // The reason both sides are padded. A marker matching as a prefix
        // classifies these on a word that is not there.
        yield 'vsauce is not vs' => ['vsauce channel', SearchIntent::Informational];
        yield 'whyte is not why' => ['whyte bike frames', SearchIntent::Informational];
        yield 'bestial is not best' => ['bestial noises', SearchIntent::Informational];
    }

    #[Test]
    #[DataProvider('queries')]
    public function intent_is_read_from_whole_words(string $query, SearchIntent $expected): void
    {
        $this->assertSame($expected, ClusterByIntent::intentOf($query));
    }

    #[Test]
    public function original_data_flags_match_whole_words_too(): void
    {
        $needs = function (string $query): bool {
            $method = new \ReflectionMethod(TypeAndFlagUnits::class, 'needsOriginalData');

            return $method->invoke(new TypeAndFlagUnits, SearchIntent::Informational, $query);
        };

        $this->assertTrue($needs('window cleaning price'));
        $this->assertTrue($needs('best window cleaner'));

        // "accurate" ends in "rate", and a unit flagged as needing real prices
        // is a unit an operator gets asked about for nothing.
        $this->assertFalse($needs('accurate window measurements'));
        $this->assertFalse($needs('how to clean windows'));
    }

    #[Test]
    public function it_maps_a_response_into_keyword_ideas(): void
    {
        Http::fake([
            'api.ahrefs.test/*' => Http::response([
                'keywords' => [
                    ['keyword' => 'how to clean windows', 'volume' => 900, 'difficulty' => 12, 'parent_topic' => 'window cleaning'],
                    ['keyword' => 'window cleaning cost', 'volume' => 400, 'difficulty' => 30],
                ],
            ]),
        ]);

        $ideas = $this->ahrefs()->matchingTerms('window cleaning', 'PT', 10);

        $this->assertCount(2, $ideas);
        $this->assertSame('how to clean windows', $ideas[0]->keyword);
        $this->assertSame(900, $ideas[0]->volume);
        $this->assertSame('window cleaning', $ideas[0]->parentTopic);
        $this->assertNull($ideas[1]->parentTopic);
    }

    #[Test]
    public function a_missing_difficulty_is_not_read_as_easy(): void
    {
        Http::fake([
            'api.ahrefs.test/*' => Http::response([
                'keywords' => [['keyword' => 'a long tail phrase', 'volume' => 70]],
            ]),
        ]);

        // Ahrefs omits difficulty for long tail rather than sending zero, and a
        // zero would send every such keyword to the top of the pool.
        $this->assertSame(50, $this->ahrefs()->matchingTerms('seed', 'us')[0]->difficulty);
    }

    #[Test]
    public function rows_with_no_keyword_are_dropped(): void
    {
        Http::fake([
            'api.ahrefs.test/*' => Http::response([
                'keywords' => [
                    ['keyword' => '', 'volume' => 100],
                    ['keyword' => 'real one', 'volume' => 100],
                ],
            ]),
        ]);

        $ideas = $this->ahrefs()->matchingTerms('seed', 'us');

        $this->assertCount(1, $ideas);
        // Re-indexed, so the caller gets a list rather than a gapped array.
        $this->assertSame('real one', $ideas[0]->keyword);
    }

    #[Test]
    public function it_asks_for_the_market_in_lower_case(): void
    {
        Http::fake(['api.ahrefs.test/*' => Http::response(['keywords' => []])]);

        $this->ahrefs()->matchingTerms('seed', 'PT', 25);

        Http::assertSent(function ($request): bool {
            return $request['country'] === 'pt' && $request['limit'] === 25;
        });
    }

    #[Test]
    public function throttling_is_retryable(): void
    {
        Http::fake(['api.ahrefs.test/*' => Http::response([], 429)]);

        // The step's retry budget should spend itself on a 429, not fail the
        // whole weekly run.
        $this->expectException(RetryableStepFailure::class);

        $this->ahrefs()->matchingTerms('seed', 'us');
    }

    #[Test]
    public function an_outage_is_retryable(): void
    {
        Http::fake(['api.ahrefs.test/*' => Http::response([], 503)]);

        $this->expectException(RetryableStepFailure::class);

        $this->ahrefs()->matchingTerms('seed', 'us');
    }

    #[Test]
    public function a_bad_request_is_not_retryable(): void
    {
        Http::fake(['api.ahrefs.test/*' => Http::response(['error' => 'bad select'], 400)]);

        // A 400 will be a 400 again in five minutes; retrying it spends quota
        // to reach the same answer.
        $this->expectException(RequestException::class);

        $this->ahrefs()->matchingTerms('seed', 'us');
    }

    #[Test]
    public function it_knows_when_it_is_not_configured(): void
    {
        config()->set('research.ahrefs.token', null);

        $this->assertFalse((new AhrefsKeywordSource)->isConfigured());

        config()->set('research.ahrefs.token', 'set');

        $this->assertTrue((new AhrefsKeywordSource)->isConfigured());
    }

    // ------------------------------------------------------------- Ahrefs

    private function ahrefs(): AhrefsKeywordSource
    {
        config()->set('research.ahrefs.token', 'test-token');
        config()->set('research.ahrefs.base_url', 'https://api.ahrefs.test');

        return new AhrefsKeywordSource;
    }
}
