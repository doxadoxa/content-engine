<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\ConversationRequest;
use App\Ai\ConversationResponse;

/**
 * The second door (§3.3), and the reason there is a second one.
 *
 * {@see ModelGateway} is one instructions string and one prompt, answered once.
 * That is the right shape for every step in every pipeline — a step knows what
 * it wants and asks for it — and it is the wrong shape for a conversation,
 * which has a history, a set of tools the model may reach for, and a loop that
 * only ends when the model stops calling them.
 *
 * Widening `send()` to carry all of that would have made every pipeline step
 * pay attention to arguments it never uses. So this is a separate interface
 * held to the same two rules, which are the rules that matter:
 *
 * - It is the **only** path a conversation may take to a provider, so the test
 *   suite binds a fake over it and no test reaches the network.
 * - It reports what the turn cost, in the same shape {@see ModelResponse} does,
 *   so a conversation is metered exactly like a pipeline step. An assistant
 *   somebody can talk to all day is the cheapest way yet invented to spend
 *   somebody else's money without it appearing on a bill.
 */
interface ConversationGateway
{
    public function converse(ConversationRequest $request): ConversationResponse;
}
