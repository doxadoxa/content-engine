<?php

declare(strict_types=1);

namespace Persistance\EngineReceiver\Middleware;

use Closure;
use Illuminate\Http\Request;
use Persistance\EngineReceiver\WebhookSignature;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse anything that is not signed, fresh and ours.
 *
 * In the package rather than in the host application's route file, because §6.3
 * is explicit about why: a receiver that *can* forget the signature check will
 * eventually forget it, and the endpoint is the one thing on the site that
 * accepts writes from the internet.
 */
class VerifyEngineSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('engine-receiver.secret');

        if ($secret === '') {
            // Refusing beats accepting unsigned content: an unconfigured
            // receiver that answered 200 would look connected and be open.
            return response()->json(['error' => 'This receiver has no secret configured.'], 503);
        }

        $signature = (string) $request->header('X-Engine-Signature', '');
        $bearer = (string) $request->bearerToken();

        $signed = WebhookSignature::verify(
            secret: $secret,
            signature: $signature,
            timestamp: $request->header('X-Engine-Timestamp'),
            body: $request->getContent(),
            tolerance: (int) config('engine-receiver.tolerance', 300),
        );

        // Both, not either. The bearer proves the sender holds the secret; the
        // signature proves the body is the one they signed and that it is not a
        // recording of a request from last month.
        if (! $signed || ! hash_equals($secret, $bearer)) {
            return response()->json(['error' => 'Bad signature.'], 401);
        }

        return $next($request);
    }
}
