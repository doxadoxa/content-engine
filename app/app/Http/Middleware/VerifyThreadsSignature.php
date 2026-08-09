<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse an inbound Threads event that Meta did not sign (§4.1).
 *
 * The mirror image of the engine's own receiver middleware, and the reasoning
 * transfers unchanged: this is the one route on the installation that accepts
 * writes from the internet with no session behind them, so the check belongs in
 * front of the controller rather than inside it, where it can be forgotten.
 *
 * Meta's scheme differs from ours in two ways worth naming, because both are
 * easy to get subtly wrong.
 *
 * It signs the **raw body** with the app secret and sends the result as
 * `X-Hub-Signature-256: sha256=<hex>`. There is no timestamp in the signature,
 * so — unlike the engine's own contract — this alone gives no replay
 * protection. That is Meta's design and not something a middleware can fix; the
 * controller closes it instead by making event handling idempotent on the
 * platform's own event id, which it has to be anyway because Meta retries.
 *
 * And the raw body must be the bytes Meta hashed. `$request->getContent()` is
 * the untouched body; anything that re-encodes the parsed JSON will produce a
 * different string and fail every legitimate request, usually only for payloads
 * containing non-ASCII — which, for a listening contour pointed at Portuguese
 * and Ukrainian conversations, is most of them.
 *
 * ⚠️ Written from Meta's published documentation and not verified against live
 * webhook traffic.
 */
class VerifyThreadsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.threads.webhook_secret', '');

        if ($secret === '') {
            // Refusing beats accepting unsigned events. An unconfigured
            // endpoint that answered 200 would look connected to Meta and be
            // open to anyone who found the URL — and Meta would stop retrying
            // the events it thinks were delivered.
            return response()->json(['error' => 'Threads webhooks are not configured on this installation.'], 503);
        }

        $provided = (string) $request->header('X-Hub-Signature-256', '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $provided)) {
            return response()->json(['error' => 'Bad signature.'], 401);
        }

        return $next($request);
    }
}
