<?php

declare(strict_types=1);

namespace App\Publishing;

use App\Enums\ChannelType;
use App\Enums\DeliveryStatus;
use App\Models\WebhookDelivery;

/**
 * What went wrong with a delivery, said to the person who has to fix it.
 *
 * The stored `error` is written for the delivery log: "receiver refused with
 * 401", or a cURL line. An owner reading "Couldn't publish" next to that has
 * to translate it and then guess what to do; this does both, once, so the
 * website page and every article status say the same thing about the same
 * failure. Messages that are already sentences — the delivery guard's, the
 * sweep's — pass through untouched.
 */
final class DeliveryExplanation
{
    /** Null while nothing has gone wrong yet: pending, or delivered. */
    public static function for(WebhookDelivery $delivery): ?string
    {
        if ($delivery->status === DeliveryStatus::Delivered) {
            return null;
        }

        return self::explain($delivery->response_code, $delivery->error, $delivery->channel?->type);
    }

    /**
     * The same, from the parts. `$type` is optional: without it a refusal
     * is explained as a webhook's, which is what most websites are.
     */
    public static function explain(?int $status, ?string $error, ?ChannelType $type = null): ?string
    {
        if ($status !== null) {
            return self::forStatus($status, $type);
        }

        if ($error === null || trim($error) === '') {
            return null;
        }

        return self::forTransport($error) ?? $error;
    }

    private static function forStatus(int $status, ?ChannelType $type): string
    {
        // WordPress signs in with a username and an application password;
        // there is no Avyo secret on that side to go and check.
        if ($type === ChannelType::WordPress && in_array($status, [401, 403], true)) {
            return "WordPress refused Avyo's login ({$status}). Check the username and application password.";
        }

        return match (true) {
            $status === 401, $status === 403 => "Your website rejected Avyo's signature ({$status}). Check that the secret on your website matches the one Avyo shows for this connection.",
            $status === 404, $status === 410 => "Nothing answered at that address ({$status}). Check the webhook address.",
            $status === 405 => 'That address does not accept articles (405). It has to accept POST requests.',
            $status === 408, $status === 504 => "Your website took too long to answer ({$status}).",
            $status === 413 => 'Your website refused the article because it is too large (413).',
            $status === 429 => 'Your website asked Avyo to slow down (429).',
            $status >= 500 => "Your website had an error ({$status}).",
            $status >= 400 => "Your website refused the article ({$status}). Its developer can see why in the website's logs.",
            $status >= 300 => "That address redirects somewhere else ({$status}). Use the final address instead.",
            // A 2xx that still failed: the receiver answered, but not with
            // the receipt this transport requires (WordPress checks one).
            default => "Your website answered, but not the way Avyo expected ({$status}). Make sure its receiver is up to date.",
        };
    }

    private static function forTransport(string $error): ?string
    {
        $lower = strtolower($error);

        return match (true) {
            str_contains($lower, 'curl error 28'), str_contains($lower, 'timed out') => 'Your website took too long to answer.',
            str_contains($lower, 'curl error 6:'), str_contains($lower, 'could not resolve') => "Avyo couldn't find that address. Check the domain name.",
            str_contains($lower, 'curl error 7:'), str_contains($lower, 'connection refused') => "Your website didn't accept the connection.",
            str_contains($lower, 'ssl'), str_contains($lower, 'certificate') => "Your website's HTTPS certificate isn't valid.",
            str_contains($lower, 'curl error') => "Avyo couldn't reach your website.",
            default => null,
        };
    }
}
