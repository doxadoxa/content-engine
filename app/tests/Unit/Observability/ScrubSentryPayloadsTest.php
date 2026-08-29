<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use App\Support\Observability\ScrubSentryPayloads;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\Tracing\Span;

/*
 * The published privacy policy tells every customer that Sentry "is not sent
 * your content". These are the assertions that keep that sentence true.
 *
 * Both leaks these cover are properties of the plumbing rather than of any
 * careless line: logging anything at all puts its context in the buffer, and
 * making any outbound request records its query string. Neither is visible
 * where it happens, so neither can be caught in review — only here.
 */
final class ScrubSentryPayloadsTest extends TestCase
{
    #[Test]
    public function a_log_breadcrumb_keeps_its_message_and_loses_its_context(): void
    {
        // The real shape from App\Visibility\DataForSeoLlmVisibility, which
        // logs the customer's prompt alongside the platform.
        $scrubbed = ScrubSentryPayloads::breadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_DEFAULT,
            'log.info',
            'An assistant returned no text',
            ['platform' => 'openai', 'prompt' => 'best cleaning service in Lisbon'],
        ));

        // The trail of what happened survives; only the values are gone.
        $this->assertSame('An assistant returned no text', $scrubbed->getMessage());
        $this->assertSame('log.info', $scrubbed->getCategory());
        $this->assertSame(Breadcrumb::LEVEL_INFO, $scrubbed->getLevel());

        $this->assertSame(
            [],
            $scrubbed->getMetadata(),
            'A log context reached Sentry, which is where customer prompts and search terms live.',
        );
    }

    #[Test]
    public function an_http_breadcrumb_keeps_the_url_and_loses_the_query(): void
    {
        // App\Research\AhrefsKeywordSource sends `keyword` as a query
        // parameter, so this string is the customer's search terms.
        $scrubbed = ScrubSentryPayloads::breadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            [
                'url' => 'https://api.ahrefs.com/v3/keywords-explorer/overview',
                'http.query' => 'keyword=best+cleaning+service+lisbon&country=pt',
                'http.request.method' => 'GET',
                'http.response.status_code' => 200,
            ],
        ));

        $metadata = $scrubbed->getMetadata();

        $this->assertStringNotContainsString('cleaning', (string) $metadata['http.query']);

        // Which endpoint, which method, what it answered — the whole
        // diagnostic value — is untouched.
        $this->assertSame('https://api.ahrefs.com/v3/keywords-explorer/overview', $metadata['url']);
        $this->assertSame('GET', $metadata['http.request.method']);
        $this->assertSame(200, $metadata['http.response.status_code']);
    }

    #[Test]
    public function an_empty_query_is_left_alone_rather_than_invented(): void
    {
        $scrubbed = ScrubSentryPayloads::breadcrumb(new Breadcrumb(
            Breadcrumb::LEVEL_INFO,
            Breadcrumb::TYPE_HTTP,
            'http',
            null,
            ['url' => 'https://example.test/feed.xml', 'http.query' => ''],
        ));

        // An empty string already says there was no query. Replacing it with a
        // placeholder would claim something was removed when nothing was.
        $this->assertSame('', $scrubbed->getMetadata()['http.query']);
    }

    #[Test]
    public function a_span_loses_its_query_too(): void
    {
        /*
         * The breadcrumb hook never sees spans, and `http.query` is recorded on
         * both — so scrubbing only breadcrumbs would close the visible half and
         * leave the same search terms riding out on performance traces.
         */
        $span = new Span;
        $span->setData([
            'url' => 'https://api.ahrefs.com/v3/keywords-explorer/overview',
            'http.query' => 'keyword=best+cleaning+service+lisbon',
        ]);

        $event = Event::createTransaction();
        $event->setSpans([$span]);

        $scrubbed = ScrubSentryPayloads::transaction($event);

        $data = $scrubbed->getSpans()[0]->getData();

        $this->assertStringNotContainsString('cleaning', (string) $data['http.query']);
        $this->assertSame('https://api.ahrefs.com/v3/keywords-explorer/overview', $data['url']);
    }
}
