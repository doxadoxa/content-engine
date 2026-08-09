<?php

declare(strict_types=1);

namespace App\Enums;

use App\Social\ReplyRouting;

/**
 * How a drafted reply can reach the thread — the shape §11.1 leaves open.
 *
 * §4.2's open question is not a detail of the adapter, it decides the form of
 * the whole contour: "позволяет ли `reply_to_id` отвечать на **чужие** посты…
 * Если нет — контур остаётся, но становится человеко-ассистирующим: движок
 * находит разговор и пишет черновик, отправляет человек."
 *
 * Both answers are the same product. The engine finds the conversation, writes
 * the draft and waits for a person either way — §4.2 forbids anything else
 * permanently. What this enum changes is what the person's one touch does: post
 * the reply through the API, or open the thread and paste it. Neither value
 * makes sending automatic, and there is no third case.
 *
 * Decided per conversation by {@see ReplyRouting}, never stored: the flag can
 * change under a queue entry, and a route written into a row at draft time
 * would be a stale answer to a question the platform may have since settled.
 */
enum ReplyRoute: string
{
    /** The engine posts it: `reply_to_id` on a container, then publish. */
    case Api = 'api';

    /**
     * The operator posts it.
     *
     * Not a refusal and not a degraded mode to apologise for. The screen gives
     * the text, a copy button and the link to the thread, and records the
     * answer when the operator says they sent it — which is the whole of §4.2
     * minus one HTTP call.
     */
    case ByHand = 'by_hand';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'Send',
            self::ByHand => 'Copy and post',
        };
    }
}
