<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Enums\SocialBand;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Support\Social\PostFormat;

/**
 * Write five to ten posts so that one can be published (§4.3).
 *
 * > `draft_candidates` генерирует **5–10 кандидатов и оставляет один**. Токены
 * > дешевле охвата: цена плохого поста — просевшее окно показа на недели
 * > вперёд. Это единственное место, где сознательно тратится дорогая модель.
 *
 * That sentence is the entire justification for this step existing, and it only
 * makes sense against §1: on this channel volume is a liability, and a weak post
 * does not score zero — it narrows the display window for the *next* one. So the
 * engine buys eight drafts and throws seven away, because the eight drafts cost
 * tokens and the wrong one costs weeks of reach.
 *
 * **One call per candidate, not one call for eight.** Asking a model for eight
 * posts in a single response gets eight paraphrases of whichever idea it thought
 * of first; asking eight times, each time for a different one of §2's shapes,
 * gets a pool that {@see RankCandidates} can actually choose between. It also
 * makes §8 legible without any special handling: eight calls are eight rows of
 * usage on this step, so `pipeline:cost` shows what selection costs rather than
 * an average hidden inside one large response.
 *
 * **The angles are §2's, and they are prescribed rather than left open.** The
 * spec names three shapes that work and four that do not, and a prompt that
 * merely says "write a good post" produces the four. Prescribing the shape does
 * not make the pool uniform — it makes the differences between its members the
 * ones worth choosing between.
 *
 * **It never decides anything.** No candidate is rejected here, however bad:
 * scoring is {@see RankCandidates}, refusing is {@see GuardPolicy}, and writing
 * is {@see SaveDraft}. A step that quietly dropped its own weakest output would
 * make the pool size a fiction and §8's arithmetic wrong.
 */
class DraftCandidates extends AbstractStep
{
    /**
     * §2's shapes that work, in the order they are worth trying.
     *
     * > Работает: вопрос, личное наблюдение с фактурой, картинка с короткой
     * > подписью.
     *
     * `link_in_thought` is the fourth and it is conditional: it is only offered
     * when the slot has something to point at, because §2's concession is that
     * "ссылка, обёрнутая в мысль или вопрос, живёт лучше" — and a prompt that
     * asks for a link when there is none invites the model to invent a URL.
     *
     * @var list<string>
     */
    private const array ANGLES = ['question', 'observation', 'caption'];

    private const string LINK_ANGLE = 'link_in_thought';

    public static function key(): string
    {
        return 'draft_candidates';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [LoadContext::key()];
    }

    public function queue(): string
    {
        return $this->expensiveQueue();
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(LoadContext::key())) {
            // The slot was skipped rather than loaded — its window had already
            // closed (§5). A skip releases dependants as if it had succeeded,
            // so this is where the chain has to stop rather than spend eight
            // expensive calls on a post that may not be published.
            return StepResult::skip('There is no slot context, so there is nothing to write candidates for.');
        }

        $slot = $context->output(LoadContext::key(), SlotContextPayload::class);

        $angles = $this->angles($slot);
        $wanted = $this->poolSize();

        $candidates = [];

        for ($i = 0; $i < $wanted; $i++) {
            $angle = $angles[$i % count($angles)];

            $answer = $context->ask(
                role: 'social_candidates',
                prompt: $this->prompt($slot, $angle),
                instructions: $this->instructions(),
            );

            $candidates[] = $this->parse($answer->text, $angle);
        }

        // §8's first line for this contour. The report reads the step rows, but
        // the pool size is what makes the number legible — "eight generations
        // for one post" is a different sentence from "one expensive step".
        $context->remember('social_draft.candidates', count($candidates));

        return StepResult::success(new CandidatePoolPayload($candidates, count($candidates)));
    }

    /**
     * How many to write, inside §4.3's own bounds.
     *
     * Clamped rather than trusted. A deployment that edits the number down to
     * one has not made the pipeline cheaper, it has turned it into the
     * generator §1 spends its first page arguing this must not be.
     */
    private function poolSize(): int
    {
        $wanted = (int) config('social.draft.candidates', 8);
        $min = (int) config('social.draft.min_candidates', 5);
        $max = (int) config('social.draft.max_candidates', 10);

        return max($min, min($max, $wanted));
    }

    /**
     * @return list<string>
     */
    private function angles(SlotContextPayload $slot): array
    {
        return $slot->signalUrl === null
            ? self::ANGLES
            : [...self::ANGLES, self::LINK_ANGLE];
    }

    private function instructions(): string
    {
        return 'You write posts for this brand on Threads. One post is one thought. You are trying to '
            .'start a conversation rather than to broadcast, because the account is measured on the '
            .'replies it gets in the first hour. You never invent a price, a figure or a date. You do not '
            .'write like a press release, a newsletter or a thread-essay.';
    }

    /**
     * One candidate's prompt: the brand, the occasion, and one shape to write in.
     *
     * The output contract is JSON with a fallback, and the fallback is
     * deliberate: a model that answers with the post itself and no envelope has
     * still answered, and {@see parse()} takes it as a single segment. Failing
     * a candidate over its wrapper would throw away a paid call for a formatting
     * mistake the ranker does not care about.
     */
    private function prompt(SlotContextPayload $slot, string $angle): string
    {
        $limit = (int) config('social.threads.text_limit', 500);

        return implode("\n\n", array_filter([
            $slot->brief === '' ? null : "How this brand sounds:\n{$slot->brief}",
            $slot->vocabulary === []
                ? null
                : 'What this project is about: '.implode(', ', array_slice($slot->vocabulary, 0, 20)),
            $slot->originalData === []
                ? null
                : "Facts about this business that are true, and the only ones you may state:\n"
                    .json_encode($slot->originalData),
            "The subject: {$slot->title}",
            $slot->signalTitle === null ? null : "Why now: {$slot->signalTitle}",
            $slot->signalUrl === null ? null : "The link, if you use one: {$slot->signalUrl}",
            $slot->parentBody === null
                ? null
                : "This post is derived from an article of ours titled \"{$slot->parentTitle}\". It has to "
                    ."stay about the same subject:\n{$slot->parentBody}",
            $slot->parentEntities === []
                ? null
                : 'Keep naming these, they are what the article was about: '
                    .implode(', ', $slot->parentEntities),
            $this->shape($angle, $slot->band),
            "Under {$limit} characters. Reply with JSON and nothing else: "
                .'{"segments":["the post"],"link":null,"chain_reason":null}. '
                .'One segment. Only add a second if the thought genuinely does not fit in one, and then '
                .'say why in chain_reason — a chain is an exception you have to justify.',
        ]));
    }

    /**
     * The one thing that differs between the calls.
     *
     * Each line is one clause of §2, written as an instruction rather than as a
     * description, because a model shown the list of what does not work will
     * write it.
     */
    private function shape(string $angle, SocialBand $band): string
    {
        return match ($angle) {
            'question' => 'Write it as a question to the reader — a real one, that somebody with an '
                .'opinion would want to answer. Not rhetorical, and not a question you then answer '
                .'yourself.',
            'observation' => 'Write it as one personal observation with something concrete in it: a '
                .'number, a price, a date, something we actually saw. No general advice.',
            'caption' => 'Write it as a short caption for a photograph — two or three sentences at most, '
                .'and end on something the reader can respond to.',
            self::LINK_ANGLE => 'Wrap the link in a thought: say what is interesting about it in your own '
                .'words and ask what the reader makes of it. A link on its own is the one thing that '
                .'reliably does not work.',
            default => "Write one post for the {$band->label()} band.",
        };
    }

    /**
     * The model's answer as a candidate.
     *
     * Tolerant in one direction only. Anything that parses as the JSON envelope
     * is read as the envelope; anything else is read as the post itself. What it
     * never does is guess at structure — a response that looks like two
     * paragraphs is one segment, because the difference between a long post and
     * a chain is a decision §2 makes the model state, not one this parser infers
     * from a blank line.
     */
    private function parse(string $text, string $angle): PostCandidate
    {
        $decoded = $this->decode($text);

        if ($decoded === null) {
            $trimmed = trim($text);

            return new PostCandidate(
                angle: $angle,
                segments: $trimmed === '' ? [] : [$trimmed],
                link: PostFormat::firstUrl($trimmed),
            );
        }

        $segments = [];

        foreach (is_array($decoded['segments'] ?? null) ? $decoded['segments'] : [] as $segment) {
            $clean = trim((string) (is_scalar($segment) ? $segment : ''));

            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        $link = is_string($decoded['link'] ?? null) && trim((string) $decoded['link']) !== ''
            ? trim((string) $decoded['link'])
            : PostFormat::firstUrl(implode("\n", $segments));

        $reason = is_string($decoded['chain_reason'] ?? null) && trim((string) $decoded['chain_reason']) !== ''
            ? trim((string) $decoded['chain_reason'])
            : null;

        return new PostCandidate(
            angle: $angle,
            segments: $segments,
            link: $link,
            // Only kept where it means something. A justification attached to a
            // single post is noise, and it would then be carried into the guard
            // as though a chain had been argued for.
            chainReason: count($segments) > 1 ? $reason : null,
        );
    }

    /**
     * The JSON object in the answer, or null if there is not one.
     *
     * Bounded by the first `{` and the last `}` so a fenced block, a preamble or
     * a trailing apology does not lose the payload.
     *
     * @return array<string, mixed>|null
     */
    private function decode(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
