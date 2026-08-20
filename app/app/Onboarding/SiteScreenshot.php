<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Support\Brand\SitePalette;
use App\Support\Http\PublicHttpTarget;
use App\Support\Http\UnsafePublicUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A picture of the site, taken by the one container that owns a browser.
 *
 * Site analysis has never seen the site. {@see HttpSiteReader} pulls the HTML
 * apart into headings, links and text, and the model that writes
 * `visual_language` is guessing a brand's appearance from its prose — which is
 * why the Brand Brief's colours have always had to be typed in by hand.
 *
 * **Optional, exactly like the panel renderer.** A deployment without
 * `RENDERER_URL` analyses sites the way it always did. This capability makes the
 * result better; it may not make the result conditional.
 *
 * **The address is validated here and pinned there.** Every other outbound fetch
 * in this engine goes through {@see PublicHttpTarget}, which resolves the host,
 * refuses private ranges and hands back the addresses so cURL can be pinned to
 * them. A browser told to open a URL would do its own DNS lookup a moment later
 * — and that moment is the whole of a rebinding attack, against a process that
 * sits on the same network as postgres and redis. So the validated addresses
 * travel with the request and Chromium is pinned to them.
 */
final class SiteScreenshot
{
    /** Desktop, because that is the layout a brand designs first. */
    public const int WIDTH = 1280;

    /**
     * One fold, not the whole page.
     *
     * The palette lives above it — the header, the hero, the first call to
     * action — and a full-page capture of a long marketing site is mostly
     * footer, testimonial grey and whatever colour the cookie banner is.
     */
    public const int HEIGHT = 900;

    public function __construct(private readonly PublicHttpTarget $targets) {}

    public function isConfigured(): bool
    {
        return $this->base() !== '';
    }

    /**
     * The site as PNG bytes, or null if it could not be photographed.
     *
     * Null rather than an exception for every failure that is not the caller's
     * fault. A site that will not load, a renderer that is down, a page that
     * takes too long — none of them are reasons to fail an onboarding run that
     * has already read the site's text successfully. The one thing that does
     * throw is an unsafe address, because that is a refusal rather than a
     * failure and the caller must not retry it.
     *
     * @throws UnsafePublicUrl
     */
    public function of(string $url): ?string
    {
        return $this->inspect($url)?->png;
    }

    /**
     * The visit itself: the picture, the colours the page declares, its fonts.
     *
     * One method rather than two, and {@see of()} delegates to it, because the
     * expensive part of both is opening somebody else's website. Reading the
     * cascade afterwards costs about a second against the three the page load
     * takes, and doing it on every visit means a caller that only wanted the
     * picture has already paid for the colours if it ever wants them.
     *
     * **Why the stylesheet at all, when there is a photograph right there.**
     * Counting pixels answers "what is there most of", which is not the same
     * question and is answered badly on the pages that need it most: it
     * quantises every colour to the nearest sixteenth, and anything smaller than
     * its sampling grid vanishes. Cleaning Point's teal came back as `#20c0c0`
     * off a screenshot and is declared `#22cbc5`; its navy came back `#002050`
     * and is declared `#002954`. The second teal in its palette, the one that
     * only ever appears as text, was not in the photograph's census at all.
     *
     * Null on the same terms as before — the picture is optional, the pin is
     * not — and an unreadable stylesheet degrades to an inspection with no
     * colours rather than to no inspection, because the pixel census in
     * {@see SitePalette} still works on the image.
     *
     * @throws UnsafePublicUrl
     */
    public function inspect(string $url): ?SiteInspection
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $target = $this->targets->validate($url);

        // No addresses means the host did not resolve and this deployment sets
        // `allow_unresolved_hosts` — fine for a plain fetch, which cURL will
        // simply fail, and not fine here. Without addresses there is nothing to
        // pin Chromium to, and an unpinned browser inside the compose network
        // resolves `postgres` and `horizon` as happily as anything else. A
        // picture is optional; the pin is not.
        if ($target->addresses === []) {
            return null;
        }

        try {
            $response = Http::timeout((int) config('content_studio.renderer.timeout', 120))
                ->acceptJson()
                ->post($this->base().'/screenshot', [
                    'url' => $target->url,
                    'host' => $target->host,
                    // Every address the guard resolved and vetted, which for a
                    // literal-IP host is that address itself.
                    'addresses' => $target->addresses,
                    'width' => self::WIDTH,
                    'height' => self::HEIGHT,
                    'timeout' => 20_000,
                    // What moves the answer into a JSON envelope. Without it the
                    // renderer replies with the raw image exactly as it always
                    // has, which is the contract anything else calling it keeps.
                    'inspect' => true,
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $encoded = $response->json('image');

        // A renderer that predates the inspection answers with the image itself,
        // exactly as it always did, and that is an ordinary state rather than a
        // fault: the two containers are built and shipped separately, so an app
        // running ahead of its renderer is a Tuesday. It degrades to what it
        // degrades to — no declared colours, and the pixel census in
        // {@see \App\Support\Brand\SitePalette} still reads the bytes.
        if (! is_string($encoded)) {
            $bytes = $response->body();

            return $bytes === '' ? null : new SiteInspection(png: $bytes);
        }

        $png = base64_decode($encoded, true);

        // A zero-length 200 is a renderer that answered without drawing, and it
        // reaches the palette as an unreadable image rather than as an error.
        if ($png === false || $png === '') {
            return null;
        }

        return new SiteInspection(
            png: $png,
            colours: $this->colours($response->json('colours')),
            fonts: $this->fonts($response->json('fonts')),
        );
    }

    /**
     * Sanitised, because this crossed a network and describes a page we do not
     * control. A malformed row is dropped rather than repaired: the palette has
     * a working fallback and half a colour is worse than none.
     *
     * @return list<array{hex: string, role: string, weight: int}>
     */
    private function colours(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['hex'] ?? null)) {
                continue;
            }

            $hex = strtolower(trim($row['hex']));

            if (preg_match('/^#[0-9a-f]{6}$/', $hex) !== 1) {
                continue;
            }

            $clean[] = [
                'hex' => $hex,
                'role' => is_string($row['role'] ?? null) ? $row['role'] : 'background',
                'weight' => max(0, (int) ($row['weight'] ?? 0)),
            ];
        }

        return $clean;
    }

    /**
     * @return list<string>
     */
    private function fonts(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];

        foreach ($rows as $row) {
            if (! is_string($row)) {
                continue;
            }

            // Trimmed to something that could be a family name. A page can set
            // font-family to anything at all and this ends up on a screen.
            $name = trim(preg_replace('/[^\p{L}\p{N} \-]/u', '', $row) ?? '');

            if ($name !== '' && mb_strlen($name) <= 64) {
                $clean[] = $name;
            }
        }

        return array_values(array_unique($clean));
    }

    private function base(): string
    {
        return rtrim((string) config('content_studio.renderer.url', ''), '/');
    }
}
