<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Steps\Repurpose\DerivativeOverlap;
use App\Social\ReplyGuard;
use App\Support\Social\PostFormat;
use App\Support\Social\ProjectVocabulary;

/**
 * The five deterministic refusals, and not one model call (§4.3).
 *
 * > `guard_policy` — **детерминированный, без вызова модели**: длина сегмента,
 * > число сегментов, запретные темы из Brief, политика ссылок, резолв
 * > сущностей. Для производной — существующее правило пересечения ≥34% с
 * > родителем; для нативного поста — сущности обязаны быть в пространстве Brief
 * > или корпуса.
 *
 * The list is the specification and the order below is the list. What makes it
 * worth stating as a property rather than as an implementation detail is that
 * every one of these is a rule a model was already *asked* to obey in
 * {@see DraftCandidates} — the prompt names the character limit, says one
 * segment, and says not to post a bare link. A model told to stay under 500
 * characters is a hope. This is the gate, and the difference between the two is
 * the difference between usually and always.
 *
 * **No model call, and that is testable rather than aspirational.** There is no
 * {@see StepContext::ask()} in this file and the step runs on the cheap queue,
 * which is the observable form of the same claim: a guard that cost tokens
 * could not run on the publish path, and a guard whose answer came from a model
 * would answer differently on Tuesday. The suite asserts the gateway saw
 * nothing from this step.
 *
 * **The overlap rule is imported, not restated.** §4.3 calls it "существующее
 * правило" and {@see DerivativeOverlap} is where it exists — the same class the
 * repurpose tree checks against, so the two cannot drift into disagreeing about
 * whether a post is about the same subject as its parent.
 *
 * **Every finding is collected before any is returned.** Stopping at the first
 * refusal would make an operator fix one thing, re-run, and find the next; §7's
 * summary is read once a day, so it has to carry everything wrong with the post
 * at the moment it was refused.
 */
class GuardPolicy extends AbstractStep
{
    public static function key(): string
    {
        return 'guard_policy';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [RankCandidates::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        if (! $context->hasOutput(RankCandidates::key())) {
            return StepResult::skip('Nothing was ranked for this slot, so there is nothing to check.');
        }

        $slot = $context->output(LoadContext::key(), SlotContextPayload::class);
        $chosen = $context->output(RankCandidates::key(), ChosenCandidatePayload::class);

        if (! $chosen->wasChosen() || $chosen->candidate === null) {
            // Nothing to guard. The slot is already empty and {@see RankCandidates}
            // has already said why, so a verdict here would be a second answer
            // to a settled question.
            return StepResult::skip('No candidate cleared the week\'s selection bar, so there is nothing to check.');
        }

        $candidate = $chosen->candidate;
        $text = $candidate->text();

        if ($text === '') {
            return StepResult::success(new GuardVerdictPayload(
                findings: [new GuardFinding(
                    GuardFinding::BLANK,
                    'The chosen candidate has no text in it, so there is no post to publish.',
                )],
                entities: [],
            ));
        }

        $findings = [
            ...$this->lengthFindings($candidate),
            ...$this->chainFindings($candidate),
            ...$this->forbiddenFindings($text, $slot),
            ...$this->linkFindings($candidate, $text),
        ];

        $entities = ProjectVocabulary::resolve($text, $slot->vocabulary);
        $overlap = null;

        if ($slot->isDerivative()) {
            $share = DerivativeOverlap::share($slot->parentEntities, $text);
            $overlap = DerivativeOverlap::percent($share);

            if (! DerivativeOverlap::meets($slot->parentEntities, $text)) {
                $findings[] = new GuardFinding(
                    GuardFinding::DERIVATIVE_OVERLAP,
                    sprintf(
                        'The post keeps %d%% of the article it is derived from, under the %d%% the '
                            .'engine requires, so it is about something else (§4.3).',
                        $overlap,
                        DerivativeOverlap::percent(DerivativeOverlap::MINIMUM),
                    ),
                );
            }
        } elseif ($entities === [] && $slot->vocabulary !== []) {
            // §4.3's other half of resolution: a native post has no parent to
            // agree with, so what it has to agree with is the project. A post
            // that names none of this project's entities is a post about
            // somebody else's subject published from our account.
            $findings[] = new GuardFinding(
                GuardFinding::UNRESOLVED_ENTITY,
                'The post resolves to none of this project\'s entities, so nothing in it says it is ours '
                    .'(§4.3). It has to name something the Brief or the corpus already knows about.',
            );
        }

        return StepResult::success(new GuardVerdictPayload(
            findings: $findings,
            entities: $entities,
            overlapPercent: $overlap,
        ));
    }

    /**
     * The platform's own limit, per segment (§2: 500 characters).
     *
     * Per segment and not over the whole post, because that is how the API
     * counts: a chain of three is three posts of 500, not one of 1 500.
     *
     * @return list<GuardFinding>
     */
    private function lengthFindings(PostCandidate $candidate): array
    {
        $limit = (int) config('social.threads.text_limit', 500);
        $findings = [];

        foreach ($candidate->segments as $position => $segment) {
            $length = mb_strlen(trim($segment));

            if ($length <= $limit) {
                continue;
            }

            $findings[] = new GuardFinding(
                GuardFinding::LENGTH,
                sprintf(
                    'Segment %d is %d characters and Threads refuses anything over %d, so the post could '
                        .'not be published as written (§2).',
                    $position + 1,
                    $length,
                    $limit,
                ),
            );
        }

        return $findings;
    }

    /**
     * How many segments there may be, and what a second one costs.
     *
     * Two rules and they are different questions. `max_segments` is a ceiling on
     * a chain that is allowed to exist; the justification is whether it was
     * allowed to exist at all. §2: "Дефолт — один пост, одна мысль, с картинкой.
     * Цепочка — исключение, которое шаг обязан обосновать, а не форма по
     * умолчанию." A chain nobody argued for is refused even at two segments.
     *
     * @return list<GuardFinding>
     */
    private function chainFindings(PostCandidate $candidate): array
    {
        $max = (int) config('social.threads.max_segments', 3);
        $count = count($candidate->segments);
        $findings = [];

        if ($count > $max) {
            $findings[] = new GuardFinding(
                GuardFinding::SEGMENT_COUNT,
                sprintf(
                    'The post is %d segments and a chain may be at most %d — past that it reads as the '
                        .'thread-essay §2 says does not work, and every segment costs one of the day\'s '
                        .'publish actions.',
                    $count,
                    $max,
                ),
            );
        }

        if ($count > 1 && $candidate->chainReason === null) {
            $findings[] = new GuardFinding(
                GuardFinding::UNJUSTIFIED_CHAIN,
                'The post is a chain with no reason given for being one. §2\'s default is one post, one '
                    .'thought, and a chain is an exception the step has to justify.',
            );
        }

        return $findings;
    }

    /**
     * Topics the Brand Brief says this project never writes about.
     *
     * Whole words rather than substrings, and `\p{L}` rather than `\b`, for the
     * reason {@see ReplyGuard} gives one contour over: a brief that forbids
     * "ICO" would otherwise match "medico" and refuse every post, which is how a
     * guard gets switched off — and `\b` is ASCII-shaped even under `/u`, which
     * matters on the Cyrillic and Portuguese briefs this engine runs.
     *
     * @return list<GuardFinding>
     */
    private function forbiddenFindings(string $text, SlotContextPayload $slot): array
    {
        $findings = [];

        foreach ($slot->forbiddenTopics as $topic) {
            $needle = trim($topic);

            if ($needle === '') {
                continue;
            }

            if (preg_match('/(?<!\p{L})'.preg_quote($needle, '/').'(?!\p{L})/iu', $text) !== 1) {
                continue;
            }

            $findings[] = new GuardFinding(
                GuardFinding::FORBIDDEN_TOPIC,
                "The post mentions “{$needle}”, which the Brand Brief says this project never writes about.",
            );
        }

        return $findings;
    }

    /**
     * §4.3's "политика ссылок", which is three rules and not a ban.
     *
     * §2 is explicit that links are no longer penalised in themselves and that a
     * link inside a thought lives better than one on its own, so the policy is
     * about the shape around the link rather than about the link:
     *
     *   - a bare link is refused, because it is the one format §2 names with no
     *     redeeming version;
     *   - a link that is not http(s) is refused, because nothing else is a link
     *     a reader can follow and a `javascript:` payload is not something this
     *     engine posts under a brand's name;
     *   - one link at most, because two is a link dump rather than a thought
     *     with a reference in it.
     *
     * @return list<GuardFinding>
     */
    private function linkFindings(PostCandidate $candidate, string $text): array
    {
        $findings = [];
        $urls = PostFormat::urlsIn($text);

        if ($candidate->link !== null && ! preg_match('#^https?://#i', $candidate->link)) {
            $findings[] = new GuardFinding(
                GuardFinding::LINK_POLICY,
                "The post's link “{$candidate->link}” is not an http or https URL, so it is not something "
                    .'a reader could follow.',
            );
        }

        if (count(array_unique($urls)) > 1) {
            $findings[] = new GuardFinding(
                GuardFinding::LINK_POLICY,
                sprintf(
                    'The post carries %d links. One thought points at one thing; more than that is a link '
                        .'dump with no conversational frame (§2).',
                    count(array_unique($urls)),
                ),
            );
        }

        if (PostFormat::isBareLink($text, $candidate->link)) {
            $findings[] = new GuardFinding(
                GuardFinding::BARE_LINK,
                sprintf(
                    'The post is a bare link: %d words besides the URL, and §2 names the bare link as one '
                        .'of the things that does not work. Wrap it in a thought or a question.',
                    PostFormat::wordsBesidesLinks($text),
                ),
            );
        }

        return $findings;
    }
}
