<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\Models\BrandBrief;
use App\Support\Social\ChannelPlaybook;

/**
 * One sentence from a reviewer, turned into a change to the post.
 *
 * {@see VisualDirector} does this for the six fields behind the picture, and
 * the argument it makes is the whole argument for this class too: an
 * instruction that reaches a generator directly, appended to a prompt, leaves
 * nothing anybody can read afterwards. The note revises the *stored thing*
 * instead — there, the art-direction fields; here, the post's own segments.
 *
 * **What it must decide before it can act** is which of the two an instruction
 * is even about. "Make it shorter" is text. "Too clinical, show the residue" is
 * the picture. "Lead with the checklist and shoot it from above" is both, and a
 * reviewer should not have to know which control to reach for — that is the
 * failure the four-tab composer had, where the fix for a picture and the fix for
 * a caption lived on different screens.
 *
 * So the model is asked to classify and to rewrite in one answer, and the
 * picture half is delegated to {@see VisualDirector} unchanged. Two model calls
 * when an instruction touches both, one when it touches one.
 *
 * **The channel's ceiling is enforced here rather than hoped for.** A rewrite
 * that comes back over the platform's limit is refused and the old text kept:
 * the alternative is a post that cannot be published sitting in the queue
 * looking finished, which is worse than an instruction that visibly did not
 * take.
 */
final class PostDirector
{
    public function __construct(private readonly VisualDirector $visuals) {}

    /**
     * @param  list<string>  $segments  the post as it stands
     * @param  array<string, string>  $visual  the six art-direction fields
     * @return array{
     *     segments: list<string>|null,
     *     visual: array<string, string>|null,
     *     reply: string,
     * }
     */
    public function revise(
        ChannelPlaybook $playbook,
        array $segments,
        array $visual,
        string $instruction,
        ?BrandBrief $brief,
        ModelSession $models,
    ): array {
        $instruction = trim($instruction);

        if ($instruction === '') {
            return ['segments' => null, 'visual' => null, 'reply' => 'Nothing to do.'];
        }

        $answer = $models->send(new ModelRequest(
            role: 'draft',
            instructions: implode("\n\n", array_filter([
                "You are editing one published-ready post for {$playbook->channel->label()} on behalf of "
                    .'the person reviewing it. They have said one thing about it. Work out whether they '
                    .'are talking about the words, the picture, or both — and change only what they asked '
                    .'about.',
                'Rewrite the words only if the note is about the words. If the note is only about the '
                    .'picture, return the segments exactly as they are.',
                $playbook->rules(),
                $brief?->compileToPrompt(),
            ])),
            prompt: implode("\n\n", [
                "The post as it stands:\n".json_encode($segments, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                "The photograph as currently briefed:\n"
                    .json_encode($visual, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                "What the reviewer said:\n".$instruction,
                "Each segment is at most {$playbook->segmentLimit} characters. Keep the same number of "
                    .'segments unless the note asks for a different length.',
                'Reply with JSON and nothing else: {"touches":"text|picture|both","segments":["..."],'
                    .'"reply":"one sentence saying what you changed"}',
            ]),
        ));

        $decoded = $this->decode($answer->text);
        $touches = $this->touches($decoded['touches'] ?? null);

        $revised = $touches === 'picture'
            ? null
            : $this->segments($decoded['segments'] ?? null, $segments, $playbook);

        // Delegated whole rather than reimplemented: the art director already
        // knows it may not invent a seventh field or empty one, and two places
        // deciding that would eventually disagree.
        $revisedVisual = $touches === 'text' || $visual === []
            ? null
            : $this->visuals->revise($visual, $instruction, $models);

        $reply = $this->text($decoded['reply'] ?? null);

        return [
            'segments' => $revised,
            'visual' => $revisedVisual,
            'reply' => $reply === ''
                ? 'Applied.'
                : $reply,
        ];
    }

    /**
     * The rewritten post, or null when it must not be applied.
     *
     * Null covers three cases that all mean "keep what is there": the model
     * returned nothing usable, it returned text identical to the original, or
     * it returned something over the platform's limit. Only the third is worth
     * a comment — a segment past the ceiling is a post that cannot be
     * published, and storing it would put a finished-looking draft in the queue
     * that the publisher will later refuse.
     *
     * @param  list<string>  $current
     * @return list<string>|null
     */
    private function segments(mixed $raw, array $current, ChannelPlaybook $playbook): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $written = [];

        foreach ($raw as $segment) {
            if (! is_string($segment)) {
                continue;
            }

            $text = trim($segment);

            if ($text === '' || mb_strlen($text) > $playbook->segmentLimit) {
                return null;
            }

            $written[] = $text;
        }

        if ($written === [] || $written === $current) {
            return null;
        }

        return $written;
    }

    private function touches(mixed $raw): string
    {
        $value = is_string($raw) ? mb_strtolower(trim($raw)) : '';

        return in_array($value, ['text', 'picture', 'both'], true) ? $value : 'both';
    }

    /** @return array<string, mixed> */
    private function decode(string $text): array
    {
        $trimmed = trim($text);
        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($trimmed, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function text(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }
}
