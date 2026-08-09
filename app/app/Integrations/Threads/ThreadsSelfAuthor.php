<?php

declare(strict_types=1);

namespace App\Integrations\Threads;

use App\Models\Interaction;
use App\Models\ProjectIntegration;
use App\Pipelines\Steps\SocialListen\Normalise;
use Illuminate\Support\Facades\Log;

/**
 * "Did we write this?" — asked of every reply before it becomes a conversation.
 *
 * §4.2's contour is event-driven: a reply arrives, an {@see Interaction}
 * is written, and a drafting job starts. Every reply the operator sends is also
 * a reply, and both intakes see it — the webhook because Meta pushes back what
 * we posted, and the reconciliation pass because `GET /{user-id}/replies` is a
 * read of the thread rather than of other people. Without this the engine
 * answers itself: our reply becomes a `new` interaction, a model drafts an
 * answer to it, the operator sends that, and it comes back. A loop and a bill,
 * and the account posting both halves of the conversation.
 *
 * The search path already got this right — {@see Normalise} refuses to classify
 * a post marked `own` as somebody's question — so this is that rule for the
 * other two intakes rather than a new idea.
 *
 * **Matched on the username and not the user id.** The id is what the platform
 * counts and what everything else here keys on, but a reply event carries an
 * author's `username` and not their id: the webhook's `value` and the fields
 * `GET /{user-id}/replies` returns both name the author that way. Matching what
 * is actually in the payload beats matching what would be tidier and then never
 * firing. Where an id *is* present it is preferred, because a username can be
 * changed and an id cannot.
 *
 * **An unknown username lets the reply through, and says so.** The username is
 * written at connect time from `GET /me`, and that call can come back without
 * one. Treating "we do not know who we are" as "this is ours" would delete the
 * whole §4.2 contour on that project — every conversation dropped, nothing in
 * the duty queue, and no error anywhere — which is a far worse failure than the
 * duplicate draft it would prevent. It is logged so the cause is visible when
 * the loop shows up.
 */
final class ThreadsSelfAuthor
{
    /**
     * Whether this reply came from the account the project connected.
     *
     * @param  array<string, mixed>  $event  the reply as the platform sent it
     */
    public static function wrote(ProjectIntegration $integration, array $event): bool
    {
        $ourId = self::string($integration->config['user_id'] ?? null);
        $theirId = self::authorId($event);

        if ($ourId !== null && $theirId !== null) {
            return $ourId === $theirId;
        }

        $ours = self::normalise(self::string($integration->config['username'] ?? null));

        if ($ours === null) {
            Log::warning('A Threads reply could not be checked against the connected account', [
                'integration' => $integration->getKey(),
                'project' => $integration->project_id,
                'reason' => 'the connection has no username on it, so a self-authored reply cannot be recognised',
            ]);

            return false;
        }

        return self::normalise(self::string($event['username'] ?? null)) === $ours;
    }

    /**
     * The author's platform id, when the payload names one.
     *
     * Two shapes because both are documented: Graph's `from: {id, username}`
     * envelope, and the flat `user_id` Threads' own webhook examples show. A
     * bare `id` is deliberately **not** read — on a reply event that is the id
     * of the reply itself, and treating it as an author would compare a post id
     * against an account id forever.
     *
     * @param  array<string, mixed>  $event
     */
    private static function authorId(array $event): ?string
    {
        $from = $event['from'] ?? null;

        if (is_array($from)) {
            $id = self::string($from['id'] ?? null);

            if ($id !== null) {
                return $id;
            }
        }

        return self::string($event['user_id'] ?? null);
    }

    /** Case-insensitive and `@`-insensitive, which is how people write a handle. */
    private static function normalise(?string $username): ?string
    {
        if ($username === null) {
            return null;
        }

        $trimmed = mb_strtolower(ltrim(trim($username), '@'));

        return $trimmed === '' ? null : $trimmed;
    }

    private static function string(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
