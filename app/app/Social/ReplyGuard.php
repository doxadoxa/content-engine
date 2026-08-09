<?php

declare(strict_types=1);

namespace App\Social;

use App\Models\BrandBrief;
use App\Models\Project;
use App\Pipelines\Steps\SocialEngage\GuardReply;
use Illuminate\Support\Carbon;

/**
 * The deterministic gate in front of every reply (§4.2, §4.3, §7, §10).
 *
 * §4.3 describes `guard_policy` for posts as "детерминированный, без вызова
 * модели: длина сегмента, число сегментов, запретные темы из Brief, политика
 * ссылок, резолв сущностей", and the half of that which applies to a reply is
 * here. No model call: a check that costs tokens cannot run on the send path,
 * and a check whose answer depends on a model is not a guard, it is a second
 * opinion.
 *
 * **It runs twice, and that is the design.** {@see GuardReply} runs it over the
 * text the engine wrote, so the duty screen can show what is wrong before the
 * operator reads a word of it. {@see InteractionReplySender} runs it again over
 * the text actually being sent, because §7 gives the operator an Edit box and a
 * guard that only ever saw the draft would be bypassed by using it. The stored
 * verdict is for the eyes; the second run is the enforcement.
 *
 * **A failed guard is shown, never swallowed.** §7: "чего движок делать не стал
 * и почему". Everything here produces a {@see ReplyGuardFinding} that reaches
 * the screen; nothing here deletes a draft or moves a conversation out of the
 * queue.
 */
final class ReplyGuard
{
    /** The model produced nothing usable. There is no reply to send. */
    public const string BLANK = 'blank';

    /** Over the platform's own limit (§2: 500 characters). Nobody can send it. */
    public const string LENGTH = 'length';

    /** A topic the Brand Brief says we never write about (§4.3). */
    public const string FORBIDDEN_TOPIC = 'forbidden_topic';

    /**
     * The fact-check of §10 found something.
     *
     * Blocking for a YMYL project while the words are still the engine's:
     * "Для cryptoroute фактчек — обязательный шаг… Неверное число в посте
     * расходится быстрее, чем в статье." It stops blocking the moment the
     * operator rewrites the text, because at that point the sentence the
     * finding was about no longer exists and the author is a person — which is
     * the entire premise of this contour. The finding stays visible either way.
     */
    public const string FACTCHECK = 'factcheck';

    /**
     * Everything wrong with `$text`, in the order an operator would want it.
     *
     * `$brief` is nullable because a project can be onboarded before anyone
     * writes a brief, and a missing brief is a missing forbidden-topic list
     * rather than a reason to refuse to reply.
     */
    public function check(string $text, Project $project, ?BrandBrief $brief): ReplyGuardVerdict
    {
        $trimmed = trim($text);
        $findings = [];

        if ($trimmed === '') {
            // Blocking and first: everything below has nothing to look at.
            return new ReplyGuardVerdict([
                new ReplyGuardFinding(
                    self::BLANK,
                    'There is no reply text. The engine wrote nothing usable for this conversation.',
                    blocking: true,
                ),
            ], Carbon::now());
        }

        $limit = $this->textLimit();
        $length = mb_strlen($trimmed);

        if ($length > $limit) {
            $findings[] = new ReplyGuardFinding(
                self::LENGTH,
                "The reply is {$length} characters. Threads refuses anything over {$limit}, so shorten it before sending.",
                blocking: true,
            );
        }

        foreach ($this->forbiddenIn($trimmed, $brief) as $topic) {
            $findings[] = new ReplyGuardFinding(
                self::FORBIDDEN_TOPIC,
                "The reply mentions “{$topic}”, which the Brand Brief says this project never writes about.",
                blocking: true,
            );
        }

        return new ReplyGuardVerdict($findings, Carbon::now());
    }

    /**
     * The fact-check verdict as a finding, or null when it found nothing.
     *
     * Separate from {@see check()} because it is the one judgement here that
     * cost a model call: it is made once, while the reply is being drafted, and
     * carried on the stored verdict from then on. Re-running it inside the
     * request an operator is waiting on would trade §4.2's minutes for a check
     * whose answer has not changed.
     *
     * @param  list<string>  $findings
     */
    public function factcheck(array $findings, bool $blocking): ?ReplyGuardFinding
    {
        if ($findings === []) {
            return null;
        }

        return new ReplyGuardFinding(
            self::FACTCHECK,
            'The fact-check could not support: '.implode('; ', $findings),
            $blocking,
        );
    }

    public function textLimit(): int
    {
        return (int) config('social.threads.text_limit', 500);
    }

    /**
     * Which forbidden topics the text actually names.
     *
     * Whole words, not substrings: a brief that forbids "ICO" would otherwise
     * match "medico" and every reply would arrive blocked, which is how a guard
     * gets switched off. `\p{L}` rather than `\b` because the boundary has to
     * hold for the Cyrillic and Portuguese briefs this engine runs on, and
     * `\b` is ASCII-shaped even under `/u`.
     *
     * @return list<string>
     */
    private function forbiddenIn(string $text, ?BrandBrief $brief): array
    {
        if ($brief === null) {
            return [];
        }

        $hits = [];

        foreach ($brief->forbidden_topics as $topic) {
            $needle = trim($topic);

            if ($needle === '') {
                continue;
            }

            $pattern = '/(?<!\p{L})'.preg_quote($needle, '/').'(?!\p{L})/iu';

            if (preg_match($pattern, $text) === 1) {
                $hits[] = $needle;
            }
        }

        return $hits;
    }
}
