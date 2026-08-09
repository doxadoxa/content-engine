<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Support\Tenancy\CurrentProject;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The pull API's front door (§9.5).
 *
 * A bearer token that *is* a `pull_api` channel's secret. That reuse is the
 * point: a pull consumer is a channel like any other, so it appears in the
 * channel list, can be disabled from the panel, and needs no second concept of
 * a credential.
 *
 * The token also selects the tenant, because there is no session here to carry
 * one — and the scope fails closed, so getting this wrong returns nothing
 * rather than everything.
 */
class AuthenticatePullApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        if ($token === '') {
            return response()->json(['error' => 'A bearer token is required.'], 401);
        }

        // Across projects: the token chooses the tenant before a tenant scope
        // exists. The deterministic HMAC is indexed and reveals no reusable
        // credential if the database is copied.
        $channel = Channel::acrossProjects()
            ->where('type', ChannelType::PullApi)
            ->where('is_enabled', true)
            ->where('token_hash', Channel::fingerprintPullToken($token))
            ->first();

        if ($channel === null) {
            return response()->json(['error' => 'Unknown token.'], 401);
        }

        app(CurrentProject::class)->set($channel->project_id);

        return $next($request);
    }
}
