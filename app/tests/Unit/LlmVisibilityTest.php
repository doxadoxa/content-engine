<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Pipelines\Exceptions\RetryableStepFailure;
use App\Visibility\BrandPresence;
use App\Visibility\DataForSeoLlmVisibility;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The assistant adapter's response mapping, and the brand match that turns an
 * answer into a number.
 *
 * The brand match matters more than it looks. It decides the headline on a
 * dashboard, so it is deterministic string work rather than a model call — two
 * runs over identical answers disagreeing would make a real change in
 * visibility indistinguishable from a judge having an off day.
 */
final class LlmVisibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('research.dataforseo.login', 'login');
        config()->set('research.dataforseo.password', 'api-password');
        config()->set('research.dataforseo.base_url', 'https://api.dataforseo.test');
    }

    // --------------------------------------------------------- brand matching

    /** @return iterable<string, array{string, string, bool}> */
    public static function mentions(): iterable
    {
        yield 'plain' => ['Try Cleaning Point for that.', 'Cleaning Point', true];
        yield 'squashed' => ['CleaningPoint is well reviewed.', 'Cleaning Point', true];
        yield 'hyphenated' => ['See cleaning-point for details.', 'Cleaning Point', true];
        yield 'case' => ['CLEANING POINT covers Lisbon.', 'Cleaning Point', true];
        yield 'absent' => ['Several firms cover Lisbon.', 'Cleaning Point', false];

        // The failure this guard exists for. A brand called "Point" inside
        // "appointment" would score 100% forever, and a percentage that cannot
        // go down is not a measurement.
        yield 'not inside a word' => ['Book an appointment today.', 'Point', false];
        yield 'partial is not a match' => ['Cleaning services in Lisbon.', 'Cleaning Point', false];

        // Diacritics are part of the word, not decoration: "Limpeza" and
        // "Limpéza" are different strings and we do not claim otherwise.
        yield 'accented brand' => ['Recomendamos a Limpeza Já.', 'Limpeza Já', true];
    }

    #[Test]
    #[DataProvider('mentions')]
    public function it_finds_a_brand_by_name(string $text, string $brand, bool $expected): void
    {
        $this->assertSame($expected, BrandPresence::namesBrand($text, $brand));
    }

    #[Test]
    public function a_two_letter_brand_is_reported_as_not_found_rather_than_always_found(): void
    {
        // "CP" appears in any answer long enough by accident. Reporting a
        // number that is always 100 is worse than reporting nothing.
        $this->assertFalse(BrandPresence::namesBrand('CP is great, and so is CPU repair.', 'CP'));
    }

    #[Test]
    public function a_citation_of_the_site_counts_even_without_the_name(): void
    {
        $citations = [['url' => 'https://blog.cleaningpoint.net/x', 'title' => 'A guide']];

        // The assistant sent the customer to the site without naming the
        // business. Subdomains count; lookalikes do not.
        $this->assertTrue(BrandPresence::citesSite($citations, 'https://cleaningpoint.net'));
        $this->assertFalse(BrandPresence::citesSite(
            [['url' => 'https://notcleaningpoint.net/x', 'title' => 'Someone else']],
            'https://cleaningpoint.net',
        ));
    }

    // ------------------------------------------------------------- the adapter

    #[Test]
    public function it_reads_the_answer_and_its_citations(): void
    {
        // The shape a live ChatGPT call returned on 2026-08-07.
        $this->fakeAnswer([[
            'money_spent' => 0.02915,
            'items' => [[
                'sections' => [[
                    'text' => 'Existem várias empresas em Lisboa, como WellCLEAN.',
                    'annotations' => [
                        ['url' => 'http://www.wellclean.pt/?utm_source=openai', 'title' => 'WellCLEAN'],
                        ['url' => 'http://www.weclean.pt/', 'title' => null],
                    ],
                ]],
            ]],
        ]]);

        $answer = app(DataForSeoLlmVisibility::class)->ask('chat_gpt', 'melhor empresa de limpeza', 'PT');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('WellCLEAN', $answer->text);
        $this->assertSame(0.02915, $answer->moneySpent);

        // A citation with no title falls back to its url rather than an empty
        // string, so the panel never renders a blank row.
        $this->assertSame('http://www.weclean.pt/', $answer->citations[1]['title']);
        $this->assertSame(['wellclean.pt', 'weclean.pt'], $answer->citedHosts());
    }

    #[Test]
    public function several_sections_become_one_answer(): void
    {
        $this->fakeAnswer([[
            'items' => [[
                'sections' => [
                    ['text' => 'First part. ', 'annotations' => [['url' => 'https://a.test/1', 'title' => 'A']]],
                    ['text' => 'Second part.', 'annotations' => [['url' => 'https://b.test/2', 'title' => 'B']]],
                ],
            ]],
        ]]);

        $answer = app(DataForSeoLlmVisibility::class)->ask('gemini', 'q');

        // Citations hang off each section rather than off the answer, so both
        // have to be walked together or half of them are lost.
        $this->assertNotNull($answer);
        $this->assertSame('First part. Second part.', $answer->text);
        $this->assertCount(2, $answer->citations);
    }

    #[Test]
    public function an_empty_answer_is_null_rather_than_a_failure(): void
    {
        $this->fakeAnswer([['items' => [['sections' => [['text' => '   ']]]]]]);

        // An assistant declining to answer "best cleaning service in Lisbon" is
        // itself a finding. Failing the run would hide it, and counting it as
        // "answered without us" would make a refusal look like a loss.
        $this->assertNull(app(DataForSeoLlmVisibility::class)->ask('claude', 'q'));
    }

    #[Test]
    public function it_asks_the_configured_model_and_turns_web_search_on(): void
    {
        // `-chat-latest` is what the ChatGPT product serves. Measuring a cheap
        // mini model instead would report what a mini model says about the
        // brand, which is not what any customer is shown.
        config()->set('visibility.platforms.chat_gpt.model', 'gpt-5.3-chat-latest');

        $this->fakeAnswer([['items' => [['sections' => [['text' => 'ok']]]]]]);

        app(DataForSeoLlmVisibility::class)->ask('chat_gpt', 'q', 'pt');

        $sent = Http::recorded()[0][0]->data()[0];

        $this->assertSame('gpt-5.3-chat-latest', $sent['model_name']);
        // Without web search the assistant answers about the brand from
        // training data, which measures what it remembered months ago rather
        // than what it would tell a customer today.
        $this->assertTrue($sent['web_search']);
        $this->assertSame('PT', $sent['web_search_country_iso_code']);
    }

    #[Test]
    public function an_assistant_that_takes_no_country_is_not_sent_one(): void
    {
        config()->set('visibility.platforms.gemini', [
            'model' => 'gemini-2.5-flash-lite',
            'accepts_country' => false,
        ]);

        $this->fakeAnswer([['items' => [['sections' => [['text' => 'ok']]]]]]);

        app(DataForSeoLlmVisibility::class)->ask('gemini', 'q', 'pt');

        // Gemini rejects the whole request over this field rather than ignoring
        // it, so one unsupported parameter cost that assistant every answer in
        // a sweep while the other three looked healthy and the score quietly
        // measured half the panel.
        $this->assertArrayNotHasKey('web_search_country_iso_code', Http::recorded()[0][0]->data()[0]);
    }

    #[Test]
    public function a_prompt_is_cut_to_the_endpoints_limit(): void
    {
        $this->fakeAnswer([['items' => [['sections' => [['text' => 'ok']]]]]]);

        app(DataForSeoLlmVisibility::class)->ask('chat_gpt', str_repeat('a', 900));

        // The belt, not the braces: GeneratePrompts already refuses anything
        // over 300 characters, because truncating a prompt changes the question
        // being measured rather than shortening it.
        $this->assertSame(500, mb_strlen(Http::recorded()[0][0]->data()[0]['user_prompt']));
    }

    #[Test]
    public function a_rate_limit_still_arrives_as_200_and_is_retryable(): void
    {
        Http::fake(['api.dataforseo.test/*' => Http::response([
            'status_code' => 20000,
            'tasks' => [['status_code' => 40202, 'status_message' => 'Rate limit.', 'result' => []]],
        ])]);

        // The same 200-that-is-not-ok as everywhere else in this API. Worth
        // asserting here too: this adapter uses the shared client, and the day
        // somebody gives it its own transport is the day it stops noticing.
        $this->expectException(RetryableStepFailure::class);

        app(DataForSeoLlmVisibility::class)->ask('chat_gpt', 'q');
    }

    #[Test]
    public function it_only_offers_platforms_that_have_a_model(): void
    {
        config()->set('visibility.platforms', [
            'chat_gpt' => ['model' => 'gpt-4.1-mini'],
            'gemini' => ['model' => ''],
        ]);

        // A platform listed without a model would be asked and would fail every
        // time, dragging the answered count down for a configuration mistake.
        $this->assertSame(['chat_gpt'], app(DataForSeoLlmVisibility::class)->platforms());
    }

    /** @param list<array<string, mixed>> $result */
    private function fakeAnswer(array $result): void
    {
        Http::fake(['api.dataforseo.test/*' => Http::response([
            'status_code' => 20000,
            'tasks' => [['status_code' => 20000, 'result' => $result]],
        ])]);
    }
}
