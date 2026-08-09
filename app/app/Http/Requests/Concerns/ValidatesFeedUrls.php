<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Rules\PublicHttpUrl;

/**
 * The wire shape of `projects.feed_urls` — the RSS whitelist of §4.1.
 *
 * Much less than {@see ValidatesDutyHours} needs, because the value is much
 * less: a flat list of addresses. What is not less is the address rule.
 * {@see PublicHttpUrl} is the same check every other operator-supplied URL in
 * the engine goes through, and it matters more here than most: this column is
 * read by an unattended hourly job that makes an outbound request to whatever
 * is in it, which is a request-forgery surface with a text input in front of
 * it. The fetch validates again on every redirect hop — an address can start
 * public and stop being one — but refusing at the form is what keeps the
 * obvious case out of the database and gives the operator an error they can
 * act on rather than a silent empty run.
 *
 * The cap is twenty. §5 gives the reactive band one post a week with a TTL, so
 * a project that needs a hundred feeds is not describing an intake — it is
 * describing a crawler, which §4.1 is not.
 */
trait ValidatesFeedUrls
{
    /**
     * @return array<string, list<mixed>>
     */
    protected static function feedUrlRules(string $key): array
    {
        return [
            $key => ['sometimes', 'nullable', 'array', 'max:20'],
            $key.'.*' => ['string', 'url', 'max:2048', app(PublicHttpUrl::class)],
        ];
    }
}
