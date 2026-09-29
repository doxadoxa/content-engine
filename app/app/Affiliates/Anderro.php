<?php

declare(strict_types=1);

namespace App\Affiliates;

use App\Affiliates\Exceptions\AffiliateEventRejected;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Anderro, the affiliate network that pays the partners who send us customers.
 *
 * Two halves, and only one of them is here. The browser half is Anderro's own
 * script, loaded by resources/js/lib/affiliates.ts once somebody allows
 * marketing cookies: it notices a `?ref=` link, writes a visitor id on our
 * domain, and tells Anderro about the click. This half tells Anderro what that
 * visitor went on to do — sign up, then pay — and it has to be the server,
 * because the calls carry the secret key and a customer's email address.
 *
 * Configured means *both* keys. The script with no server half would record
 * clicks that can never be credited, and the server half with no script has no
 * visitor to credit them to. Either one alone is a partner programme that looks
 * switched on and never pays out, so neither runs without the other.
 */
final class Anderro
{
    /** Where the script is served from and the events are sent to. Also what the CSP allows. */
    public const string ORIGIN = 'https://track.anderro.com';

    public function isConfigured(): bool
    {
        return $this->publicKey() !== null && $this->secretKey() !== null;
    }

    /** The key the browser script identifies itself with. Public by design. */
    public function publicKey(): ?string
    {
        return $this->key('services.anderro.public_key');
    }

    /**
     * This visitor signed up. The visitor id is what links the address to the
     * partner's link, so it is required rather than optional here.
     *
     * @throws AffiliateEventRejected
     * @throws ConnectionException
     */
    public function signup(string $email, string $visitorId): void
    {
        $this->send([
            'type' => 'signup',
            'customerEmail' => $email,
            'visitorId' => $visitorId,
        ]);
    }

    /**
     * This customer paid. No visitor id: once the signup is recorded, Anderro
     * attributes by address, which is what lets a renewal months later be
     * credited without the browser that clicked the link.
     *
     * @throws AffiliateEventRejected
     * @throws ConnectionException
     */
    public function payment(string $email, int $amountCents): void
    {
        $this->send([
            'type' => 'payment',
            'customerEmail' => $email,
            'amountCents' => $amountCents,
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     *
     * @throws AffiliateEventRejected
     * @throws ConnectionException
     */
    private function send(array $event): void
    {
        $response = Http::withHeaders(['x-api-key' => (string) $this->secretKey()])
            ->acceptJson()
            ->timeout(10)
            ->post(self::ORIGIN.'/events', $event);

        // Accepted is a 202 *and* `ok: true`. A deduplicated signup is still
        // `ok`, and is exactly as good as the first one.
        if ($response->successful() && $response->json('ok') === true) {
            return;
        }

        $error = $response->json('error');

        throw new AffiliateEventRejected(
            status: $response->status(),
            reason: is_string($error) ? $error : null,
        );
    }

    private function secretKey(): ?string
    {
        return $this->key('services.anderro.secret_key');
    }

    private function key(string $name): ?string
    {
        $value = config($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
