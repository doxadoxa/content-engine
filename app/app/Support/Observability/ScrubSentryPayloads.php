<?php

declare(strict_types=1);

namespace App\Support\Observability;

use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * The last thing between a customer's work and a third party's index.
 *
 * `send_default_pii` is off, and it is worth being precise about what that
 * does and does not buy. It stops the SDK attaching things *it* collects — the
 * request body, the cookies, the IP address, the signed-in user. It does
 * nothing whatsoever about values this application hands to Sentry itself, and
 * we hand it a great many by accident:
 *
 *  - Every `Log::` call becomes a breadcrumb carrying its context array
 *    verbatim, at every level, whether or not that channel reports to Sentry.
 *    `Log::info('An assistant returned no text', ['prompt' => $prompt])` in
 *    App\Visibility\DataForSeoLlmVisibility is a customer's prompt sitting in
 *    the buffer, and the next exception in that request or job carries it out.
 *  - Every outbound HTTP call records `http.query`, on both the breadcrumb and
 *    the span. App\Research\AhrefsKeywordSource sends `keyword` as a query
 *    parameter, so the customer's search terms are in that string.
 *
 * Neither is hypothetical and neither is visible at the call site, which is the
 * problem with both: the leak is a property of logging at all, not of any
 * careless line, so it cannot be fixed by being careful. It has to be closed
 * here, once, on the way out.
 *
 * This is not decoration. config/legal.php names Sentry as a subprocessor and
 * the privacy policy renders that entry, which tells every customer in writing
 * that Sentry "is not sent your content". Without this class that sentence is
 * false, and a false statement about processing is the failure config/legal.php
 * puts above all others.
 *
 * Registered from config/sentry.php as an array callable rather than a closure
 * on purpose: the production entrypoint runs `php artisan config:cache`, and a
 * closure in a config file makes that command fail outright.
 */
final class ScrubSentryPayloads
{
    /**
     * Keys whose values are, or can contain, something a customer wrote.
     *
     * A query string is the whole of it today. It is the one field that is
     * structured enough to look harmless — a URL is normally fine to record —
     * and free-form enough to hold anything the caller put in it.
     */
    private const REDACTED_KEYS = [
        'http.query',
        'http.fragment',
    ];

    private const PLACEHOLDER = '[scrubbed]';

    /**
     * Breadcrumbs, on the way into the buffer.
     *
     * Always returns one. Sentry treats a null return as "drop this", which is
     * not what any of this is for: the trail of what happened is the reason to
     * keep breadcrumbs at all, and it is the values inside them that have to go.
     */
    public static function breadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $metadata = $breadcrumb->getMetadata();

        /*
         * A log breadcrumb's metadata *is* the application's log context, which
         * is arbitrary by construction — there is no list of safe keys to keep,
         * because the next person to write a `Log::` call decides what goes in
         * it. So none of it is kept.
         *
         * The message survives, and that is what makes this cheap rather than
         * destructive: the messages in this codebase are written as static
         * sentences ("A pipeline step job was killed"), so the trail of what
         * happened before a fault is intact and only the values are gone. The
         * residual risk is a message that interpolates content directly, which
         * would be a deliberate act at the call site rather than an accident of
         * the plumbing.
         */
        if (str_starts_with($breadcrumb->getCategory(), 'log.')) {
            $metadata = [];
        }

        return new Breadcrumb(
            $breadcrumb->getLevel(),
            $breadcrumb->getType(),
            $breadcrumb->getCategory(),
            $breadcrumb->getMessage(),
            self::redact($metadata),
            $breadcrumb->getTimestamp(),
        );
    }

    /**
     * Transactions, on the way out.
     *
     * The breadcrumb hook does not see spans, and `http.query` is recorded on
     * both — so scrubbing only breadcrumbs would close the visible half of the
     * leak and leave performance traces carrying the same search terms.
     */
    public static function transaction(Event $event): Event
    {
        foreach ($event->getSpans() as $span) {
            $data = $span->getData();
            $redacted = self::redact($data);

            if ($redacted !== $data) {
                $span->setData($redacted);
            }
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function redact(array $data): array
    {
        foreach (self::REDACTED_KEYS as $key) {
            // Only when there is something there. Replacing an absent key with
            // a placeholder would invent a field, and an empty query string is
            // already telling you there was no query.
            if (($data[$key] ?? '') !== '') {
                $data[$key] = self::PLACEHOLDER;
            }
        }

        return $data;
    }
}
