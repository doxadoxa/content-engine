<?php

declare(strict_types=1);

namespace App\Content;

use App\Console\Commands\SocialKillExpiredCommand;
use App\Models\ContentItem;
use App\Pipelines\Steps\SocialDraft\GuardPolicy;
use App\Support\Social\ChannelPayload;
use App\Support\Social\ChannelPayloadSegment;
use App\Support\Social\PostFormat;
use InvalidArgumentException;

/**
 * What a social post ships with, as a checklist and a number — §2's checklist,
 * not the article one.
 *
 * {@see ArticleScore} answers a different question. Its critical checks are
 * "names real limitations" and "reads as written, not generated", both of which
 * are about a two-thousand-word page; run against a 300-character post they
 * fail every time, which would make the approve button permanently dead for the
 * one kind of unit §4.3 produces. A post is not a short article and grading it
 * as one is how the whole contour of §4.3 dead-ends.
 *
 * So the checks below are the ones §2 and §4.3 actually state, and the critical
 * ones are exactly the refusals that already exist elsewhere in the engine:
 *
 *   - there is text to send, and every segment is inside the platform's limit
 *     ({@see GuardPolicy} refuses both);
 *   - the chain is no longer than a chain may be;
 *   - it is not a bare link, «единственный формат без искупительной версии»;
 *   - and its window has not closed — §5's reactive band kills a draft that
 *     missed its moment rather than publishing it late, and an operator who
 *     opens the queue an hour after {@see SocialKillExpiredCommand}
 *     would have run must not be able to send yesterday's comment on the news.
 *
 * **The gate is asked again at approval, though the guard already asked it.**
 * Not redundancy: the guard ran when the draft was written, and a post can sit
 * in the queue for a day. Its TTL expires in that time, and its text can be
 * edited. The approval screen is the last place before delivery where a human
 * is looking, so the rules are re-read against the row as it stands rather than
 * trusted from a run that finished yesterday.
 *
 * **What is not critical is still on the row.** A post with no question in it
 * and no figure in it is a weak post and §2 says so, but it is a judgement an
 * operator is allowed to make. Those move the number and never block, which is
 * the same split {@see ArticleScore} draws between `critical` and the rest.
 */
class PostScore
{
    /**
     * @return array{
     *     score: int,
     *     publishable: bool,
     *     blocking: list<string>,
     *     checks: list<array{key: string, label: string, ok: bool, detail: string, severity: string}>,
     * }
     */
    public function for(ContentItem $item): array
    {
        $payload = $this->payload($item);
        $segments = array_map(
            static fn (ChannelPayloadSegment $segment): string => trim($segment->text),
            $payload === null ? [] : $payload->segments,
        );
        $text = trim(implode("\n\n", $segments));

        $limit = $this->textLimit();
        $maxSegments = $this->maxSegments();
        $tooLong = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => mb_strlen($segment) > $limit,
        ));

        $factcheck = $item->factcheck;
        $factcheckRan = ($factcheck['required'] ?? false) === true || ($factcheck['findings'] ?? []) !== [];

        $checks = [
            $this->check(
                'has_text',
                'Has a post to send',
                $text !== '',
                $payload === null
                    ? 'no channel payload on this unit'
                    : ($text === '' ? 'the payload has no text in it' : count($segments).' segment(s)'),
                weight: 3,
                severity: 'critical',
            ),
            $this->check(
                'within_limit',
                'Inside the platform\'s limit',
                $tooLong === [],
                $tooLong === []
                    ? 'longest segment '.$this->longest($segments)."/{$limit} characters"
                    : count($tooLong)." segment(s) over {$limit} characters",
                weight: 3,
                // The API refuses it. There is no version of "approve anyway"
                // that ends in a published post.
                severity: 'critical',
            ),
            $this->check(
                'chain_length',
                'One thought, or a chain short enough to read',
                count($segments) <= $maxSegments,
                count($segments)." of at most {$maxSegments} segment(s)",
                weight: 2,
                severity: 'critical',
            ),
            $this->check(
                'not_a_bare_link',
                'More than a link',
                ! PostFormat::isBareLink($text, $payload?->linkAttachment),
                PostFormat::hasLink($text, $payload?->linkAttachment)
                    ? PostFormat::wordsBesidesLinks($text).' words around the link'
                    : 'no link in it',
                weight: 2,
                severity: 'critical',
            ),
            $this->check(
                'in_window',
                'Still inside its window',
                ! $item->hasExpired(),
                $item->expires_at === null
                    ? 'no expiry'
                    : ($item->hasExpired()
                        ? 'the window closed '.$item->expires_at->diffForHumans()
                        : 'closes '.$item->expires_at->diffForHumans()),
                weight: 2,
                // §5: a reactive draft that missed its window is killed rather
                // than published late, because an automatic comment on the day
                // before yesterday's news is worse than silence.
                severity: 'critical',
            ),

            // Below here nothing blocks. §2 describes what works and what does
            // not; an operator may still decide a flat post is worth sending.
            $this->check(
                'invites_a_reply',
                'Invites a reply',
                PostFormat::invitesAReply($text),
                PostFormat::invitesAReply($text) ? 'asks something' : 'nothing to answer',
                // §1's first fact is that the algorithm weighs replies, which
                // makes this the single highest-leverage property of a post.
                weight: 3,
            ),
            $this->check(
                'substance',
                'Something checkable in it',
                PostFormat::hasSubstance($text),
                PostFormat::hasSubstance($text) ? 'carries a figure' : 'no price, date or count',
                weight: 2,
            ),
            $this->check(
                'image',
                'Picture on the root',
                $this->hasImage($payload),
                $this->hasImage($payload) ? 'attached' : 'none',
            ),
            $this->check(
                'entities',
                'Says whose subject this is',
                $item->entities !== [],
                $item->entities === []
                    ? 'resolves to none of this project\'s entities'
                    : count($item->entities).' entity(ies)',
                weight: 2,
            ),
            // Only when §10 asked for one. On a project that is not YMYL there
            // is no fact-check to report and a failed check would be a check
            // that never ran.
            ...($factcheckRan
                ? [$this->check(
                    'factcheck',
                    'Claims the fact-check could support',
                    ($factcheck['passed'] ?? false) === true,
                    ($factcheck['passed'] ?? false) === true
                        ? 'checked'
                        : count($factcheck['findings'] ?? []).' finding(s)',
                    weight: 2,
                )]
                : []),
        ];

        $critical = array_values(array_filter(
            $checks,
            static fn (array $check): bool => ! $check['ok'] && $check['severity'] === 'critical',
        ));

        return [
            'score' => $this->score($checks),
            'publishable' => $critical === [],
            'blocking' => array_map(
                static fn (array $check): string => $check['label'],
                $critical,
            ),
            'checks' => array_map(
                static fn (array $check): array => [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'ok' => $check['ok'],
                    'detail' => $check['detail'],
                    'severity' => $check['severity'],
                ],
                $checks,
            ),
        ];
    }

    /**
     * The post as §3's payload, or null when there is not one to read.
     *
     * A malformed payload is read as an absent one rather than allowed to throw.
     * The queue renders a row per draft and one unreadable JSON column would
     * take the whole screen down; as an absent payload it fails `has_text`,
     * which is the honest answer — nothing here can be published.
     */
    private function payload(ContentItem $item): ?ChannelPayload
    {
        $raw = $item->channel_payload;

        if (! is_array($raw) || $raw === []) {
            return null;
        }

        try {
            return ChannelPayload::fromArray($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function hasImage(?ChannelPayload $payload): bool
    {
        if ($payload === null) {
            return false;
        }

        return ($payload->segments[0] ?? null)?->assetId !== null;
    }

    /** @param list<string> $segments */
    private function longest(array $segments): int
    {
        return array_reduce(
            $segments,
            static fn (int $carry, string $segment): int => max($carry, mb_strlen($segment)),
            0,
        );
    }

    private function textLimit(): int
    {
        return (int) config('social.threads.text_limit', 500);
    }

    private function maxSegments(): int
    {
        return (int) config('social.threads.max_segments', 3);
    }

    /**
     * @param  list<array{key: string, label: string, ok: bool, detail: string, weight: int, severity: string}>  $checks
     */
    private function score(array $checks): int
    {
        $total = array_sum(array_column($checks, 'weight'));

        if ($total === 0) {
            return 0;
        }

        $earned = array_sum(array_map(
            static fn (array $check): int => $check['ok'] ? $check['weight'] : 0,
            $checks,
        ));

        return (int) round(($earned / $total) * 100);
    }

    /**
     * @return array{key: string, label: string, ok: bool, detail: string, weight: int, severity: string}
     */
    private function check(
        string $key,
        string $label,
        bool $ok,
        string $detail,
        int $weight = 1,
        string $severity = 'warning',
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => $ok,
            'detail' => $detail,
            'weight' => $weight,
            'severity' => $severity,
        ];
    }
}
