<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Publishing\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The blog's webhook accepts nothing that is not signed, fresh and ours.
 *
 * The same check the engine-receiver package makes, written against the
 * application's own {@see WebhookSignature}: the package is a dev dependency
 * and absent from the production image, and here the sender and the receiver
 * are one codebase, so there is no reason to verify with a copy of the scheme.
 */
final class VerifyBlogSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('blog.webhook_secret');

        if ($secret === '') {
            // An unconfigured blog that answered 200 would look connected and
            // accept articles from anyone who found the path.
            return response()->json(['error' => 'The blog has no webhook secret configured.'], 503);
        }

        $signed = WebhookSignature::verify(
            secret: $secret,
            signature: (string) $request->header('X-Engine-Signature', ''),
            timestamp: $request->header('X-Engine-Timestamp'),
            body: $request->getContent(),
            tolerance: (int) config('blog.tolerance', 300),
        );

        // Both, not either. The bearer proves the sender holds the secret; the
        // signature proves the body is the one they signed, and recently.
        if (! $signed || ! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Bad signature.'], 401);
        }

        return $next($request);
    }
}
