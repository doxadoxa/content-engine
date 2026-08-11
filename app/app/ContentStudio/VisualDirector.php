<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\Support\Social\SocialImagePrompt;

/**
 * What an operator says about a picture, turned back into the six fields.
 *
 * The alternative was to let the sentence reach the image provider directly,
 * appended to the prompt, and it is worth saying why that is worse rather than
 * simply not doing it. A prompt that grows by one clause each time somebody
 * disagrees with it ends up carrying every previous complaint, including the
 * ones that were satisfied three pictures ago, and none of them can be edited
 * because none of them is stored as anything an editor could reach. After four
 * rounds nobody — operator or engine — can say what the brief currently is.
 *
 * So the sentence revises the structure instead. "Less clinical, show more of
 * the residue" becomes new values for `subject` and `light`, those values are
 * what gets stored on the draft, and the next revision starts from them. The
 * brief stays a thing with six named parts that a person can read, which is the
 * same argument {@see SocialImagePrompt} makes for having them at all.
 *
 * **It cannot invent a seventh field and it cannot empty one.** A revision that
 * came back missing `light` would silently fall to the house default and quietly
 * undo whatever the last three rounds had established there, so anything the
 * model leaves out keeps the value it had.
 */
final class VisualDirector
{
    /**
     * The six fields as the operator's instruction would have them.
     *
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    public function revise(array $current, string $instruction, ModelSession $models): array
    {
        $instruction = trim($instruction);

        if ($instruction === '') {
            return $current;
        }

        $answer = $models->send(new ModelRequest(
            role: 'draft',
            instructions: 'You are the art director for a brand\'s social photography. You are given the '
                .'six fields that describe one photograph and one note from the person reviewing it. '
                .'Rewrite only what the note asks about and leave the rest exactly as it is. Never ask '
                .'for text, words or logos in the picture, and never build the shot around an appliance, '
                .'a run of cabinetry or a room.',
            prompt: implode("\n\n", [
                "The photograph as currently briefed:\n".json_encode($current, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                "What the reviewer said:\n".$instruction,
                'Reply with JSON and nothing else: {"subject":"...","composition":"...","action":"...",'
                    .'"location":"...","style":"...","light":"..."}',
            ]),
        ));

        return $this->merge($current, $answer->text);
    }

    /**
     * The model's answer over the current fields, never under them.
     *
     * Anything unreadable leaves the brief as it was, which is the only safe
     * direction: the fields on the draft are the accumulated result of every
     * revision so far, and a malformed answer must cost the operator one call,
     * not the work of the last four.
     *
     * @param  array<string, string>  $current
     * @return array<string, string>
     */
    private function merge(array $current, string $text): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return $current;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        if (! is_array($decoded)) {
            return $current;
        }

        $revised = $current;

        foreach (array_keys($current) as $field) {
            $value = $decoded[$field] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $revised[$field] = trim($value);
            }
        }

        return $revised;
    }
}
