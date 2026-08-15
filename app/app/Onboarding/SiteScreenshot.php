<?php

declare(strict_types=1);

namespace App\Onboarding;

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
                ]);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();

        // A zero-length 200 is a renderer that answered without drawing, and it
        // reaches the palette as an unreadable image rather than as an error.
        return $bytes === '' ? null : $bytes;
    }

    private function base(): string
    {
        return rtrim((string) config('content_studio.renderer.url', ''), '/');
    }
}
