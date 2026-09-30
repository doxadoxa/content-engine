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
 * failure. Sentences Avyo itself writes on a delivery — the guard's, the
 * sweeper's, the send-back — are known here and reworded; anything else is
 * an unnamed failure, and the raw text stays for the history's details only.
 */
final class DeliveryExplanation
{
    /** For a failure nobody has written words for. The raw text stays in the history's details. */
    public const string GENERIC = "Avyo couldn't send it.";

    public const string SENT_BACK = "It was sent back for changes, so it wasn't sent.";

    public const string DELAYED = 'Taking longer than usual. Avyo will try again automatically.';

    public const string GAVE_UP = "Avyo couldn't get it to your website after several tries.";

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

        return self::forTransport($error) ?? self::forInternal($error) ?? self::GENERIC;
    }

    /**
     * Whether a failure is final for this article: it was taken back, or its
     * schedule moved on, so "Try again" would only be refused.
     */
    public static function isWithdrawn(?string $error): bool
    {
        $lower = strtolower((string) $error);

        return str_contains($lower, 'sent back for rework') || str_contains($lower, 'schedule changed before this delivery');
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

    /** Avyo's own sentences, in the words the rest of the product uses. */
    private static function forInternal(string $error): ?string
    {
        $lower = strtolower($error);

        return match (true) {
            StrandedDeliveries::isRequeueNote($error) => self::DELAYED,
            StrandedDeliveries::isAbandonedNote($error) => self::GAVE_UP,
            $error === WebhookPublisher::WAITING_FOR_WEBSITE => 'Waiting for your website. Avyo will send it as soon as your website connection passes a test.',
            $error === WebhookPublisher::WEBSITE_STAYED_BROKEN => $error,
            str_contains($lower, 'sent back for rework') => self::SENT_BACK,
            str_contains($lower, 'schedule changed before this delivery') => "Its schedule changed before it went out, so it wasn't sent.",
            str_contains($lower, 'no longer matches') => "Its schedule changed after it was queued, so it wasn't sent. Pick a new date to publish it.",
            str_contains($lower, 'changed after this delivery was queued') => "The article changed after it was queued, so it wasn't sent. Review it, then publish it again.",
            str_contains($lower, 'fact check') => 'The fact check found something to look at. Review the article, then approve it.',
            str_contains($lower, 'missed') => 'Its date passed before it went out. Pick a new date to publish it.',
            str_contains($lower, 'paused for this project'), str_contains($lower, 'publication is paused') => 'Publishing is paused for this business or its plan.',
            str_contains($lower, 'automatic publishing was turned off') => 'Automatic publishing was turned off before it went out. Pick a new date to publish it.',
            str_contains($lower, 'no longer enabled'), str_contains($lower, 'website connection') => "Your website connection isn't working. Test it on the Website page.",
            str_contains($lower, 'no webhook address'), str_contains($lower, 'no endpoint') => 'No webhook address is set for this website. Add it on the Website page.',
            // Already written for owners: the approval hold and the business-facts checks.
            str_contains($lower, 'approve the article first'), str_contains($lower, 'business information') => $error,
            default => null,
        };
    }
}
