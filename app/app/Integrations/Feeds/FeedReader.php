<?php

declare(strict_types=1);

namespace App\Integrations\Feeds;

use App\Models\Project;
use App\Support\Http\PublicHttpClient;
use App\Support\Http\UnsafePublicUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SimpleXMLElement;
use Throwable;

/**
 * The RSS whitelist of §4.1, read.
 *
 * The third intake into the listening contour, next to `keyword_search` and the
 * webhooks, and the only one pointed at addresses an operator typed. Everything
 * unusual about this class follows from that: it is an unattended hourly job
 * making outbound requests to third-party XML.
 *
 * **Every request goes through {@see PublicHttpClient}.** Not because it is the
 * house client, but because it re-validates at every redirect hop. A feed that
 * was public when it was added and 301s to `http://169.254.169.254/` today is
 * the whole reason the outbound policy exists, and a fetch that validated only
 * the address it was given would follow it. Nothing in this class ever builds a
 * request from a URL found *inside* a feed — item links are carried as data and
 * fetched by nobody.
 *
 * **One broken feed must not take the run down.** {@see read()} never throws.
 * A refused address, a connection that times out, a 500, a body that is not XML
 * at all, a parser that gives up halfway — all of them are logged and answered
 * with an empty list, because the alternative is that the least reliable
 * publisher on a project's list decides whether the other nineteen are read.
 *
 * **Feeds lie about their encoding.** Not occasionally: a declaration saying
 * UTF-8 over Windows-1252 bytes is one of the most common things on the open
 * web, and libxml refuses the whole document over one byte rather than
 * dropping a character. {@see transcode()} settles it before the parser sees
 * it, and the stakes are concrete — this engine runs Portuguese and Ukrainian
 * projects, where the mis-encoded characters are not decoration but most of the
 * consonants.
 *
 * **Entities are never expanded.** `LIBXML_NOENT` is deliberately absent and
 * `LIBXML_NONET` deliberately present. An XML document from an address a user
 * typed is the textbook XXE delivery vehicle, and the flag that would make it
 * work is the flag that would make it dangerous.
 */
final class FeedReader
{
    private const string USER_AGENT = 'ContentEngine/1.0 (+listening)';

    private const int TIMEOUT = 15;

    /** Feeds move hosts. Three hops is generous and terminates. */
    private const int MAX_REDIRECTS = 3;

    /** Two megabytes of XML is a very long feed. More is a mistake or a trap. */
    private const int MAX_BYTES = 2_097_152;

    /** Per feed. §5 caps the reactive band at one post a week regardless. */
    private const int MAX_ITEMS = 50;

    private const string DUBLIN_CORE = 'http://purl.org/dc/elements/1.1/';

    public function __construct(private readonly PublicHttpClient $http) {}

    /**
     * Every item from every feed on a project's whitelist.
     *
     * The order is the operator's: a project that put its own industry's
     * newsletter first meant something by it, and sorting by date here would
     * throw that away for a contour that has to choose one story a week.
     *
     * @return list<FeedItem>
     */
    public function readProject(Project $project): array
    {
        return $this->readAll($project->feedUrls());
    }

    /**
     * @param  list<string>  $urls
     * @return list<FeedItem>
     */
    public function readAll(array $urls): array
    {
        $items = [];

        foreach ($urls as $url) {
            foreach ($this->read($url) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * One feed, or nothing.
     *
     * Never throws — see the class docblock. The catch-all `Throwable` is not
     * laziness: `simplexml_load_string` and `mb_convert_encoding` have both
     * been made to raise on malformed input across PHP versions, and the
     * contract this method offers its caller is worth more than the precision
     * of the catch.
     *
     * @return list<FeedItem>
     */
    public function read(string $url): array
    {
        try {
            $response = $this->http->request(
                'GET',
                $url,
                [
                    'Accept' => 'application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, */*;q=0.5',
                    'User-Agent' => self::USER_AGENT,
                ],
                self::TIMEOUT,
                self::MAX_REDIRECTS,
            )->response;
        } catch (UnsafePublicUrl $e) {
            // Either the address was never public or a redirect tried to take
            // us somewhere private. Notice rather than info: the operator put
            // this address in, and the second case is worth someone reading.
            Log::notice('A project feed was refused by the outbound policy', [
                'feed' => $url,
                'reason' => $e->getMessage(),
            ]);

            return [];
        } catch (ConnectionException $e) {
            Log::info('A project feed did not answer', ['feed' => $url, 'reason' => $e->getMessage()]);

            return [];
        } catch (Throwable $e) {
            Log::warning('A project feed could not be fetched', ['feed' => $url, 'reason' => $e->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::info('A project feed answered an error', ['feed' => $url, 'status' => $response->status()]);

            return [];
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            Log::notice('A project feed was larger than this engine reads', [
                'feed' => $url,
                'bytes' => strlen($body),
            ]);

            return [];
        }

        try {
            return $this->parse($url, $this->transcode($body, $response->header('Content-Type')));
        } catch (Throwable $e) {
            Log::warning('A project feed could not be parsed', ['feed' => $url, 'reason' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<FeedItem>
     */
    private function parse(string $url, string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // No LIBXML_NOENT — see the class docblock. LIBXML_NOCDATA because half
        // the feeds on the web wrap their titles in CDATA and the other half do
        // not, and that is not a distinction worth carrying downstream.
        $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NONET);

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $xml instanceof SimpleXMLElement) {
            Log::info('A project feed was not readable XML', [
                'feed' => $url,
                'reason' => $errors === [] ? 'unknown' : trim($errors[0]->message),
            ]);

            return [];
        }

        return match ($xml->getName()) {
            // RSS 2.0 and the 0.9x dialects that share its shape.
            'rss' => $this->fromRss($url, $xml->channel, $xml->channel->item),
            // RSS 1.0, where <item> is a sibling of <channel> rather than a
            // child of it. Handled because it is what several government and
            // university feeds still emit.
            'RDF' => $this->fromRss($url, $xml->channel, $xml->item),
            'feed' => $this->fromAtom($url, $xml),
            default => $this->unknown($url, $xml->getName()),
        };
    }

    /**
     * @return list<FeedItem>
     */
    private function unknown(string $url, string $root): array
    {
        Log::info('A project feed is in a format this engine does not read', [
            'feed' => $url,
            'root' => $root,
        ]);

        return [];
    }

    /**
     * @return list<FeedItem>
     */
    private function fromRss(string $url, ?SimpleXMLElement $channel, ?SimpleXMLElement $items): array
    {
        if ($items === null) {
            return [];
        }

        $feedTitle = $channel === null ? null : $this->text($channel->title);
        $read = [];

        foreach ($items as $item) {
            if (count($read) >= self::MAX_ITEMS) {
                break;
            }

            $link = $this->link($this->text($item->link));
            $title = $this->text($item->title);
            $summary = $this->summary($this->text($item->description));

            // The guid first, because it is what the publisher promises is
            // stable; the link second, because it usually is anyway.
            $id = $this->text($item->guid) ?? $link ?? $this->fallbackId($url, $title, $summary);

            $date = $this->text($item->pubDate) ?? $this->dublinCoreDate($item);

            $entry = $this->item($url, $feedTitle, $id, $title, $link, $summary, $date);

            if ($entry !== null) {
                $read[] = $entry;
            }
        }

        return $read;
    }

    /**
     * @return list<FeedItem>
     */
    private function fromAtom(string $url, SimpleXMLElement $feed): array
    {
        $feedTitle = $this->text($feed->title);
        $read = [];

        foreach ($feed->entry as $entry) {
            if (count($read) >= self::MAX_ITEMS) {
                break;
            }

            $link = $this->atomLink($entry);
            $title = $this->text($entry->title);
            $summary = $this->summary($this->text($entry->summary) ?? $this->text($entry->content));
            $id = $this->text($entry->id) ?? $link ?? $this->fallbackId($url, $title, $summary);

            // `published` first: `updated` is the last edit, and a typo fixed
            // this morning is not a story that happened this morning. §5's
            // reactive band kills a draft that misses its window, so reading
            // the wrong one publishes a comment on old news or drops fresh news
            // as stale.
            $date = $this->text($entry->published) ?? $this->text($entry->updated);

            $item = $this->item($url, $feedTitle, $id, $title, $link, $summary, $date);

            if ($item !== null) {
                $read[] = $item;
            }
        }

        return $read;
    }

    private function item(
        string $url,
        ?string $feedTitle,
        string $id,
        ?string $title,
        ?string $link,
        ?string $summary,
        ?string $date,
    ): ?FeedItem {
        $title ??= $summary === null ? null : Str::limit($summary, 120);

        if ($title === null || $title === '') {
            // Nothing to say about it and nothing to fingerprint it by. A
            // signal needs a subject — `Signal::fingerprintFor()` refuses an
            // empty one rather than colliding every untitled row in a project.
            return null;
        }

        return new FeedItem(
            feedUrl: $url,
            feedTitle: $feedTitle,
            id: $id,
            title: Str::limit(Str::squish(strip_tags($title)), 300, ''),
            url: $link,
            summary: $summary,
            publishedAt: $this->date($date),
        );
    }

    /**
     * The `alternate` link of an Atom entry — the human-readable page.
     *
     * Atom entries carry several links: `self`, `edit`, `enclosure`, `replies`.
     * Taking the first would hand the planner an API endpoint or a podcast
     * audio file about as often as an article.
     */
    private function atomLink(SimpleXMLElement $entry): ?string
    {
        $fallback = null;

        foreach ($entry->link as $link) {
            $rel = isset($link['rel']) ? (string) $link['rel'] : 'alternate';
            $href = isset($link['href']) ? $this->link((string) $link['href']) : null;

            if ($href === null) {
                continue;
            }

            if ($rel === 'alternate') {
                return $href;
            }

            $fallback ??= $href;
        }

        return $fallback;
    }

    /**
     * An address worth carrying, or null.
     *
     * Scheme only, and no DNS: this is data, not a request. Nothing in the
     * engine fetches an item link, and anything that ever does must put it
     * through `PublicHttpTarget` at that moment rather than trusting a check
     * made hours earlier by a different process. What this does close is the
     * cheap one — a `javascript:` or `data:` link reaching an operator screen
     * as something clickable.
     */
    private function link(?string $href): ?string
    {
        if ($href === null) {
            return null;
        }

        $href = trim($href);
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $href : null;
    }

    private function summary(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = Str::squish(strip_tags($value));

        return $text === '' ? null : Str::limit($text, 500, '');
    }

    /**
     * A last-resort identity for an entry with neither a guid nor a link.
     *
     * Derived from the feed and the content rather than random, because the
     * point of the id is that the same entry read next hour is the same id. A
     * random one would re-deliver every item of a badly-built feed every hour,
     * which is precisely the duplicate flood §4.1's dedup exists to stop.
     */
    private function fallbackId(string $url, ?string $title, ?string $summary): string
    {
        return 'sha256:'.substr(hash('sha256', implode('|', [$url, $title ?? '', $summary ?? ''])), 0, 32);
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->utc();
        } catch (Throwable) {
            // Null, never now(). An item stamped with the moment it was read
            // looks fresh forever, and §5 kills reactive drafts by age.
            return null;
        }
    }

    /**
     * `<dc:date>`, which is where RSS 1.0 keeps the thing RSS 2.0 calls
     * `pubDate` — and which several RSS 2.0 feeds carry as well, alongside no
     * `pubDate` at all.
     */
    private function dublinCoreDate(SimpleXMLElement $item): ?string
    {
        $dublinCore = $item->children(self::DUBLIN_CORE);

        return $dublinCore === null ? null : $this->text($dublinCore->date);
    }

    private function text(?SimpleXMLElement $node): ?string
    {
        if ($node === null) {
            return null;
        }

        $value = trim((string) $node);

        return $value === '' ? null : $value;
    }

    /**
     * Make the bytes UTF-8, whatever the document claims they are.
     *
     * In this order, because each step only makes sense after the one before:
     *
     * 1. Drop a byte-order mark. libxml treats a BOM before `<?xml` as content
     *    and refuses the document.
     * 2. Read the declared encoding — from the XML declaration if there is one,
     *    from the `Content-Type` charset if there is not.
     * 3. Convert if that is not UTF-8, then check the result. A feed that
     *    declares UTF-8 and is not is the common lie, so the check runs whether
     *    or not a conversion happened, and Windows-1252 is the fallback because
     *    it is what "UTF-8" usually means when it is wrong.
     * 4. Rewrite the declaration to UTF-8, so the parser does not re-apply the
     *    encoding that has just been undone.
     * 5. Strip the control characters XML 1.0 does not allow. Feeds carry them,
     *    libxml rejects the whole document over one, and none of them is
     *    content.
     */
    private function transcode(string $body, ?string $contentType): string
    {
        foreach (["\xEF\xBB\xBF", "\xFE\xFF", "\xFF\xFE"] as $bom) {
            if (str_starts_with($body, $bom)) {
                $body = substr($body, strlen($bom));

                break;
            }
        }

        $declared = null;

        if (preg_match('/^<\?xml[^>]*encoding\s*=\s*["\']([A-Za-z0-9_.:-]+)["\']/', $body, $found) === 1) {
            $declared = strtoupper($found[1]);
        } elseif (is_string($contentType) && preg_match('/charset\s*=\s*"?([A-Za-z0-9_.:-]+)"?/i', $contentType, $found) === 1) {
            $declared = strtoupper($found[1]);
        }

        if ($declared !== null && ! in_array($declared, ['UTF-8', 'UTF8', 'US-ASCII'], true)) {
            $converted = @mb_convert_encoding($body, 'UTF-8', $declared);

            if (is_string($converted) && $converted !== '') {
                $body = $converted;
            }
        }

        if (! mb_check_encoding($body, 'UTF-8')) {
            // Windows-1252 is what "UTF-8" means when it is a lie: it is the
            // default of every Windows text editor a small publisher writes a
            // feed in, and it maps every byte, so the conversion cannot fail
            // the way a strict one would.
            $body = mb_convert_encoding($body, 'UTF-8', 'Windows-1252');
        }

        $body = (string) preg_replace(
            '/(<\?xml[^>]*encoding\s*=\s*["\'])[A-Za-z0-9_.:-]+(["\'])/',
            '${1}UTF-8${2}',
            $body,
            1,
        );

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $body);
    }
}
