<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Research\DataForSeoKeywordSource;
use ArrayObject;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The DataForSEO adapter's response mapping and — mostly — its error handling.
 *
 * The suite binds the fake behind the port, so without this the adapter would
 * ship entirely unexercised. `Http::fake` is not the network: it is the
 * documented response shape, asserted.
 *
 * Most of what follows is about one property of this API that has no equivalent
 * in the Ahrefs adapter it replaces: **failures arrive as HTTP 200**. Only 401,
 * 402 and 404 are HTTP errors. A rate limit, an internal timeout, a malformed
 * field and an empty result are all successful requests carrying a status code
 * in the body, so every one of them would read as "no keywords found" to code
 * that trusted the status line — and a research run that finds no keywords
 * plans an empty month rather than failing.
 */
final class DataForSeoKeywordSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('research.dataforseo.login', 'login');
        config()->set('research.dataforseo.password', 'api-password');
        config()->set('research.dataforseo.base_url', 'https://api.dataforseo.test');
        config()->set('research.dataforseo.keyword_endpoint', 'suggestions');

        // The location map is cached across calls on purpose, which would
        // otherwise leak one test's fixture into the next.
        Cache::forget('dataforseo.locations_and_languages');
    }

    // ------------------------------------------------------ keyword mapping

    #[Test]
    public function it_maps_a_suggestions_response_into_keyword_ideas(): void
    {
        $this->fakeWith('keyword_suggestions', [[
            'items' => [
                [
                    'keyword' => 'house cleaning lisbon',
                    'keyword_info' => ['search_volume' => 250, 'cpc' => 1.4],
                    'keyword_properties' => ['keyword_difficulty' => 12, 'core_keyword' => null],
                ],
                [
                    'keyword' => 'house cleaning lisbon prices',
                    'keyword_info' => ['search_volume' => 90],
                    'keyword_properties' => ['keyword_difficulty' => 30, 'core_keyword' => 'house cleaning lisbon'],
                ],
            ],
        ]]);

        $ideas = $this->source()->matchingTerms('house cleaning lisbon', 'PT', 50, 'en');

        $this->assertCount(2, $ideas);
        $this->assertSame('house cleaning lisbon', $ideas[0]->keyword);
        $this->assertSame(250, $ideas[0]->volume);
        $this->assertSame(12, $ideas[0]->difficulty);

        // core_keyword is null when the keyword *is* the core of its group,
        // which is what our clustering already means by a null parent.
        $this->assertNull($ideas[0]->parentTopic);
        $this->assertSame('house cleaning lisbon', $ideas[1]->parentTopic);
    }

    #[Test]
    public function the_monthly_curve_is_kept_instead_of_thrown_away(): void
    {
        // `monthly_searches` arrives unasked for inside every `keyword_info`
        // block and used to be dropped on the floor — §5 calls picking it up
        // "одно поле и один метод, не интеграция", and this is the field half.
        $this->fakeWith('keyword_suggestions', [[
            'items' => [[
                'keyword' => 'christmas cleaning',
                'keyword_info' => [
                    'search_volume' => 400,
                    'monthly_searches' => [
                        ['year' => 2025, 'month' => 12, 'search_volume' => 900],
                        ['year' => 2026, 'month' => 1, 'search_volume' => 120],
                        // The vendor's window is about twelve months, so it can
                        // straddle a year and report one calendar month twice.
                        ['year' => 2026, 'month' => 12, 'search_volume' => 700],
                    ],
                ],
                'keyword_properties' => ['keyword_difficulty' => 20],
            ]],
        ]]);

        $idea = $this->source()->matchingTerms('christmas cleaning', 'PT', 50, 'en')[0];

        // Averaged rather than summed: a summed December would read as twice
        // the season it is, purely because of when the question was asked.
        $this->assertSame([1 => 120, 12 => 800], $idea->volumeByMonth);
        $this->assertSame(12, $idea->seasonality()->peakMonth());
    }

    #[Test]
    public function a_keyword_with_no_curve_simply_has_no_season(): void
    {
        $this->fakeWith('keyword_suggestions', [[
            'items' => [[
                'keyword' => 'house cleaning lisbon',
                'keyword_info' => ['search_volume' => 250],
                'keyword_properties' => ['keyword_difficulty' => 12],
            ]],
        ]]);

        $idea = $this->source()->matchingTerms('house cleaning lisbon', 'PT', 50, 'en')[0];

        // A missing curve is not an error and not a flat year — it is silence,
        // and the seasonal band of §5 must simply not fire on it.
        $this->assertSame([], $idea->volumeByMonth);
        $this->assertNull($idea->seasonality()->peakMonth());
    }

    #[Test]
    public function an_unmeasured_difficulty_is_not_read_as_easy(): void
    {
        $this->fakeWith('keyword_suggestions', [[
            'items' => [[
                'keyword' => 'a long tail phrase',
                'keyword_info' => ['search_volume' => 70],
                'keyword_properties' => ['keyword_difficulty' => null],
            ]],
        ]]);

        // Zero would mean "trivial to rank for" and put this at the head of the
        // pool, past `maximum_difficulty`. The whole reason the switch matters
        // here: Ahrefs sent a literal 0 for unmeasured Portuguese terms, so
        // this guard never fired for the project it was written for.
        $this->assertSame(50, $this->source()->matchingTerms('seed', 'PT')[0]->difficulty);
    }

    #[Test]
    public function a_genuine_zero_difficulty_survives(): void
    {
        $this->fakeWith('keyword_suggestions', [[
            'items' => [[
                'keyword' => 'nobody competes for this',
                'keyword_info' => ['search_volume' => 60],
                'keyword_properties' => ['keyword_difficulty' => 0],
            ]],
        ]]);

        // The opposite mistake. "Not measured" and "measured, and it is zero"
        // are different facts and only one of them should become 50.
        $this->assertSame(0, $this->source()->matchingTerms('seed', 'PT')[0]->difficulty);
    }

    #[Test]
    public function a_missing_volume_is_zero_rather_than_a_fatal(): void
    {
        $this->fakeWith('keyword_suggestions', [[
            'items' => [
                ['keyword' => 'no info block at all'],
                ['keyword' => 'null volume', 'keyword_info' => ['search_volume' => null]],
            ],
        ]]);

        $ideas = $this->source()->matchingTerms('seed', 'PT');

        $this->assertSame([0, 0], array_map(fn ($idea): int => $idea->volume, $ideas));

        // Both then fail `minimum_volume` — which is the right outcome, and it
        // is reached without the mapping guessing anything.
        $this->assertSame(50, $ideas[0]->difficulty);
    }

    #[Test]
    public function keywords_with_no_metrics_at_all_are_reported_as_a_shape_problem(): void
    {
        $logged = $this->captureLogs();

        $this->fakeWith('keyword_suggestions', [[
            'items' => [
                // How the response would look if it nested one level deeper
                // than the mapping expects — which is how a sibling Labs
                // endpoint actually answers.
                ['keyword' => 'one', 'keyword_data' => ['keyword_info' => ['search_volume' => 900]]],
                ['keyword' => 'two', 'keyword_data' => ['keyword_info' => ['search_volume' => 400]]],
            ],
        ]]);

        $ideas = $this->source()->matchingTerms('seed', 'PT');

        // Downstream this is indistinguishable from a genuinely obscure seed:
        // every volume is zero, the pool empties against `minimum_volume`, and
        // research reports a thin market rather than a wrong field path.
        $this->assertSame([0, 0], array_map(fn ($idea): int => $idea->volume, $ideas));

        $this->assertCount(1, $logged);
        $this->assertSame('warning', $logged[0]['level']);
        $this->assertStringContainsString('no metrics at all', $logged[0]['message']);
    }

    #[Test]
    public function a_normal_response_says_nothing(): void
    {
        $logged = $this->captureLogs();

        $this->fakeWith('keyword_suggestions', [[
            'items' => [['keyword' => 'one', 'keyword_info' => ['search_volume' => 900]]],
        ]]);

        $this->source()->matchingTerms('seed', 'PT');

        // A warning on every healthy run is a warning nobody reads.
        $this->assertSame([], $logged->getArrayCopy());
    }

    #[Test]
    public function rows_with_no_keyword_are_dropped_and_the_list_is_reindexed(): void
    {
        $this->fakeWith('keyword_suggestions', [[
            'items' => [
                ['keyword' => '   ', 'keyword_info' => ['search_volume' => 100]],
                ['keyword_info' => ['search_volume' => 100]],
                ['keyword' => 'real one', 'keyword_info' => ['search_volume' => 100]],
            ],
        ]]);

        $ideas = $this->source()->matchingTerms('seed', 'PT');

        $this->assertCount(1, $ideas);
        $this->assertSame('real one', $ideas[0]->keyword);
    }

    #[Test]
    public function a_result_with_no_items_is_an_empty_pool_not_a_crash(): void
    {
        $this->fakeWith('keyword_suggestions', [['items' => null, 'total_count' => 0]]);

        $this->assertSame([], $this->source()->matchingTerms('seed', 'PT'));
    }

    #[Test]
    public function an_empty_seed_is_not_sent_at_all(): void
    {
        Http::fake();

        $this->assertSame([], $this->source()->matchingTerms('   ', 'PT'));
        $this->assertSame([], $this->source()->rankingPages('', 'PT'));

        // Including the free locations lookup: a request nobody can answer is
        // not worth resolving a country for.
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------- the SERP

    #[Test]
    public function only_organic_items_become_ranking_pages(): void
    {
        $this->fakeWith('organic/live/advanced', [[
            'items' => [
                ['type' => 'ai_overview', 'title' => 'AI Overview'],
                ['type' => 'organic', 'rank_group' => 1, 'url' => 'https://a.test/one', 'title' => 'One'],
                ['type' => 'people_also_ask', 'title' => 'How much is house cleaning?'],
                ['type' => 'local_pack', 'title' => 'Cleaners near you', 'url' => 'https://maps.test'],
                ['type' => 'organic', 'rank_group' => 2, 'url' => 'https://b.test/two', 'title' => 'Two'],
            ],
        ]]);

        $pages = $this->source()->rankingPages('house cleaning lisbon', 'PT', 10, 'en');

        // One flat array carries the whole result page. The Ahrefs adapter
        // dropped these by accident — they happened to have a null url — and a
        // local_pack entry has a perfectly good one, so accident is not enough.
        $this->assertSame(
            ['https://a.test/one', 'https://b.test/two'],
            array_column($pages, 'url'),
        );
    }

    #[Test]
    public function a_page_with_no_title_falls_back_to_its_url(): void
    {
        $this->fakeWith('organic/live/advanced', [[
            'items' => [['type' => 'organic', 'url' => 'https://a.test/one', 'title' => null]],
        ]]);

        $this->assertSame('https://a.test/one', $this->source()->rankingPages('q', 'PT')[0]['title']);
    }

    #[Test]
    public function it_buys_only_the_depth_it_needs(): void
    {
        $this->fakeWith('organic/live/advanced', [['items' => []]]);

        $this->source()->rankingPages('q', 'PT', 8);

        // Billed per ten results. Asking for twenty to use eight is money spent
        // on every article this engine writes.
        $this->assertSame(10, $this->lastTask('organic/live/advanced')['depth']);

        $this->source()->rankingPages('q', 'PT', 15);

        $this->assertSame(20, $this->lastTask('organic/live/advanced')['depth']);
    }

    #[Test]
    public function the_limit_is_honoured_even_when_the_serp_is_deeper(): void
    {
        $this->fakeWith('organic/live/advanced', [[
            'items' => array_map(
                fn (int $i): array => ['type' => 'organic', 'url' => "https://a.test/{$i}", 'title' => "T{$i}"],
                range(1, 10),
            ),
        ]]);

        $this->assertCount(3, $this->source()->rankingPages('q', 'PT', 3));
    }

    // ------------------------------------------------------ market and language

    #[Test]
    public function the_market_becomes_a_google_geo_target_id(): void
    {
        $this->fakeWith('keyword_suggestions', [['items' => []]]);

        $this->source()->matchingTerms('seed', 'pt', 50, 'en');

        $task = $this->lastTask('keyword_suggestions');

        // Read from the free endpoint rather than computed. The country ids
        // happen to be 2000 plus the ISO numeric code, and a wrong-but-plausible
        // one targets a real country that is not the project's.
        $this->assertSame(2620, $task['location_code']);
        $this->assertSame('en', $task['language_code']);
    }

    #[Test]
    public function a_locale_is_reduced_to_its_language(): void
    {
        $this->fakeWith('keyword_suggestions', [['items' => []]]);

        $this->source()->matchingTerms('seed', 'PT', 50, 'pt-PT');

        $this->assertSame('pt', $this->lastTask('keyword_suggestions')['language_code']);
    }

    #[Test]
    public function a_language_the_market_has_no_corpus_for_is_left_off(): void
    {
        $this->fakeWith('keyword_suggestions', [['items' => []]]);

        $this->source()->matchingTerms('seed', 'PT', 50, 'ja');

        // Sending it is rejected outright, and substituting the market's
        // default is worse than not asking: a project that chose English would
        // silently get its volumes from the Portuguese search instead. That
        // exact mismatch has shipped here once already.
        $this->assertArrayNotHasKey('language_code', $this->lastTask('keyword_suggestions'));
    }

    #[Test]
    public function no_language_asked_for_means_none_sent(): void
    {
        $this->fakeWith('keyword_suggestions', [['items' => []]]);

        $this->source()->matchingTerms('seed', 'PT');

        $this->assertArrayNotHasKey('language_code', $this->lastTask('keyword_suggestions'));
    }

    #[Test]
    public function a_market_with_no_keyword_data_fails_terminally(): void
    {
        $this->fakeWith('keyword_suggestions', [['items' => []]]);

        // A project configured for a country the vendor does not cover is a
        // settings problem. Retrying spends the run's budget to be told so
        // three more times.
        $this->expectException(TerminalStepFailure::class);
        $this->expectExceptionMessage("market 'ZZ'");

        $this->source()->matchingTerms('seed', 'ZZ');
    }

    #[Test]
    public function the_location_map_is_fetched_once_and_reused(): void
    {
        Http::fake([
            'api.dataforseo.test/v3/dataforseo_labs/locations_and_languages*' => Http::response($this->locations()),
            'api.dataforseo.test/*' => Http::response([
                'status_code' => 20000,
                'tasks' => [['status_code' => 20000, 'result' => [['items' => []]]]],
            ]),
        ]);

        $source = $this->source();
        $source->matchingTerms('one', 'PT');
        $source->matchingTerms('two', 'PT');
        $source->rankingPages('three', 'PT');

        // Free, but not instant, and every research run resolves the same
        // country. Three lookups here would be three round trips per seed.
        Http::assertSentCount(4);
    }

    // -------------------------------------------------- the 200-that-is-not-ok

    #[Test]
    public function a_rate_limit_arrives_as_200_and_is_still_retryable(): void
    {
        $this->fakeWith('keyword_suggestions', [], status: 40202);

        $this->expectException(RetryableStepFailure::class);

        $this->source()->matchingTerms('seed', 'PT');
    }

    #[Test]
    public function an_internal_failure_arrives_as_200_and_is_still_retryable(): void
    {
        // The whole 50xxx family is theirs: internal timeouts, a third party
        // they depend on, an index update in progress.
        $this->fakeWith('keyword_suggestions', [], taskStatus: 50401);

        $this->expectException(RetryableStepFailure::class);

        $this->source()->matchingTerms('seed', 'PT');
    }

    #[Test]
    public function a_malformed_request_arrives_as_200_and_is_terminal(): void
    {
        $this->fakeWith('keyword_suggestions', [], taskStatus: 40501);

        // 40501 is "invalid POST data". It will be invalid again in five
        // minutes; retrying spends quota to reach the same answer.
        $this->expectException(TerminalStepFailure::class);

        $this->source()->matchingTerms('seed', 'PT');
    }

    #[Test]
    public function no_search_results_is_an_empty_pool_rather_than_a_failure(): void
    {
        $this->fakeWith('keyword_suggestions', [], taskStatus: 40102);

        // 40102 is a well-formed question with no answer. Failing the run on it
        // would turn "this seed matched nothing" — which FetchKeywords already
        // logs by name — into a dead pipeline.
        $this->assertSame([], $this->source()->matchingTerms('seed', 'PT'));
    }

    #[Test]
    public function an_empty_task_list_under_a_200_is_retryable(): void
    {
        Http::fake([
            'api.dataforseo.test/v3/dataforseo_labs/locations_and_languages*' => Http::response($this->locations()),
            'api.dataforseo.test/*' => Http::response(['status_code' => 20000, 'tasks' => []]),
        ]);

        // Undocumented, which is exactly why it should say so rather than
        // return [] and read as "no keywords".
        $this->expectException(RetryableStepFailure::class);

        $this->source()->matchingTerms('seed', 'PT');
    }

    // ------------------------------------------------- the three real HTTP errors

    #[Test]
    public function bad_credentials_say_which_password_is_meant(): void
    {
        Http::fake(['api.dataforseo.test/*' => Http::response([], 401)]);

        $this->expectException(TerminalStepFailure::class);

        // The mistake this message exists for: DataForSEO issues a separate API
        // password, and signing-in credentials return 401 exactly as a typo
        // would. The second half is the stale-worker trap, which looks
        // identical from the outside.
        $this->expectExceptionMessage('not the password you log into the site with');

        $this->source()->matchingTerms('seed', 'PT');
    }

    #[Test]
    public function an_empty_balance_is_terminal_and_says_so(): void
    {
        Http::fake(['api.dataforseo.test/*' => Http::response([], 402)]);

        $this->expectException(TerminalStepFailure::class);
        $this->expectExceptionMessage('balance is empty');

        $this->source()->matchingTerms('seed', 'PT');
    }

    #[Test]
    public function an_outage_is_retryable(): void
    {
        Http::fake(['api.dataforseo.test/*' => Http::response([], 503)]);

        $this->expectException(RetryableStepFailure::class);

        $this->source()->matchingTerms('seed', 'PT');
    }

    // ----------------------------------------------------------- configuration

    #[Test]
    public function it_knows_when_it_is_not_configured(): void
    {
        $this->assertTrue($this->source()->isConfigured());

        // Half a credential is not a credential; the login alone authenticates
        // nothing and would fail at the first call instead of at the check.
        config()->set('research.dataforseo.password', null);

        $this->assertFalse($this->source()->isConfigured());
    }

    #[Test]
    public function the_ideas_endpoint_sends_a_keyword_list_instead_of_a_phrase(): void
    {
        config()->set('research.dataforseo.keyword_endpoint', 'ideas');

        $this->fakeWith('keyword_ideas', [['items' => []]]);

        $this->source()->matchingTerms('house cleaning lisbon', 'PT');

        // The two endpoints differ in exactly this. Getting it backwards is a
        // 40501 on every research run, which the suite would otherwise only
        // discover in production.
        $task = $this->lastTask('keyword_ideas');

        $this->assertSame(['house cleaning lisbon'], $task['keywords']);
        $this->assertArrayNotHasKey('keyword', $task);

        // keyword_ideas has no such parameter, and this API rejects unknown
        // fields rather than ignoring them — so sending it would be a 40501 on
        // every research run the moment somebody flipped the setting.
        $this->assertArrayNotHasKey('include_seed_keyword', $task);
    }

    #[Test]
    public function the_suggestions_endpoint_sends_a_single_phrase(): void
    {
        $this->fakeWith('keyword_suggestions', [['items' => []]]);

        $this->source()->matchingTerms('house cleaning lisbon', 'PT');

        $task = $this->lastTask('keyword_suggestions');

        $this->assertSame('house cleaning lisbon', $task['keyword']);
        $this->assertArrayNotHasKey('keywords', $task);

        // Ahrefs' matching-terms included the seed itself and the pool's
        // ordering assumes it is there; suggestions drops it unless asked.
        $this->assertTrue($task['include_seed_keyword']);
    }

    // ------------------------------------------------------------------ helpers

    private function source(): DataForSeoKeywordSource
    {
        return app(DataForSeoKeywordSource::class);
    }

    /**
     * Collects what gets logged, by reference, from the moment it is called.
     *
     * The log event rather than a facade spy: a spy replaces the logger, so
     * anything that reads back what it wrote would see nothing, and the
     * assertion ends up about Mockery instead of about the message.
     *
     * An ArrayObject rather than an array, because the listener fires after
     * this has returned and a returned array is a copy.
     *
     * @return ArrayObject<int, array{level: string, message: string}>
     */
    private function captureLogs(): ArrayObject
    {
        /** @var ArrayObject<int, array{level: string, message: string}> $logged */
        $logged = new ArrayObject;

        Log::listen(function (MessageLogged $event) use ($logged): void {
            $logged[] = ['level' => $event->level, 'message' => $event->message];
        });

        return $logged;
    }

    /**
     * Fakes the free locations lookup plus one data endpoint.
     *
     * @param  list<array<string, mixed>>  $result
     */
    private function fakeWith(string $endpoint, array $result, int $status = 20000, int $taskStatus = 20000): void
    {
        Http::fake([
            'api.dataforseo.test/v3/dataforseo_labs/locations_and_languages*' => Http::response($this->locations()),
            "api.dataforseo.test/*{$endpoint}*" => Http::response([
                'version' => '0.1.20260101',
                'status_code' => $status,
                'status_message' => $status === 20000 ? 'Ok.' : 'Something the body says.',
                'cost' => 0.0103,
                'tasks' => [[
                    'id' => '08051200-1535-0066-0000-c2ee4a1b8f31',
                    'status_code' => $taskStatus,
                    'status_message' => $taskStatus === 20000 ? 'Ok.' : 'Something the task says.',
                    'result' => $result,
                ]],
            ]),
        ]);
    }

    /**
     * The shape of /v3/dataforseo_labs/locations_and_languages, trimmed.
     *
     * @return array<string, mixed>
     */
    private function locations(): array
    {
        return [
            'status_code' => 20000,
            'tasks' => [[
                'status_code' => 20000,
                'result' => [
                    [
                        'location_code' => 2620,
                        'location_name' => 'Portugal',
                        'location_code_parent' => null,
                        'country_iso_code' => 'PT',
                        'location_type' => 'Country',
                        'available_languages' => [
                            ['language_name' => 'Portuguese', 'language_code' => 'pt'],
                            ['language_name' => 'English', 'language_code' => 'en'],
                        ],
                    ],
                    [
                        'location_code' => 2840,
                        'location_name' => 'United States',
                        'location_code_parent' => null,
                        'country_iso_code' => 'US',
                        'location_type' => 'Country',
                        'available_languages' => [['language_name' => 'English', 'language_code' => 'en']],
                    ],
                ],
            ]],
        ];
    }

    /**
     * The single task out of the last POST to an endpoint.
     *
     * @return array<string, mixed>
     */
    private function lastTask(string $endpoint): array
    {
        $sent = collect(Http::recorded())
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $endpoint))
            ->last();

        $this->assertNotNull($sent, "Nothing was sent to {$endpoint}.");

        /** @var array<string, mixed> $task */
        $task = $sent[0]->data()[0];

        return $task;
    }
}
