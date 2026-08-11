<?php

declare(strict_types=1);

namespace App\ContentStudio;

use App\Pipelines\Steps\SocialDraft\GuardFinding;
use App\Support\Social\ChannelPlaybook;
use App\Support\Social\ChannelPostScore;
use App\Support\Social\StudioPostGuard;

/**
 * One attempt at one post, before anything has decided whether it is the one.
 *
 * The Studio had no object like this because it had nothing to choose between:
 * a single model call returned all three channels at once and whatever it said
 * became the draft. Writing several and keeping one needs a thing that can be
 * held, scored and compared, and needs it to carry every channel's shape —
 * Threads and X are segments, Instagram is a caption and possibly slides.
 *
 * One class for all three rather than a hierarchy, because the differences are
 * two nullable fields and the similarities are everything the selection cares
 * about. A subclass per channel would put the interesting code — score against
 * a bar, prefer the clean one — behind a polymorphic call and make the
 * selection unreadable in exchange for modelling a distinction nothing acts on.
 */
final readonly class StudioCandidate
{
    /**
     * @param  list<string>  $segments  the post, one entry per published segment
     * @param  list<array{heading: string, body: string}>  $slides  carousel panels, empty for every other format
     * @param  array<string, mixed>  $visual  the six art-direction fields, as the model returned them
     * @param  list<GuardFinding>  $findings  why this may not be published, empty when it may
     */
    public function __construct(
        public string $angle,
        public string $format,
        public array $segments,
        public ?string $link = null,
        public ?string $chainReason = null,
        public array $slides = [],
        public array $visual = [],
        public int $score = 0,
        public array $findings = [],
    ) {}

    /** The post as one string: what gets published, one segment after another. */
    public function text(): string
    {
        return trim(implode("\n\n", $this->segments));
    }

    /**
     * The carousel's panels as text, empty for every other format.
     *
     * Separate from {@see text()} because the two are read for different
     * reasons. The segments are what the platform publishes and what the
     * length and chain rules are about; the panels are not segments and must
     * not be counted as any — but they *are* words that go out under the
     * brand's name, and they were being checked by nothing at all. A forbidden
     * topic or an invented figure on slide four is the same problem as one in
     * the caption, and it is the slide an operator is more likely to skim.
     */
    public function panelText(): string
    {
        return trim(implode("\n\n", array_map(
            static fn (array $slide): string => trim($slide['heading']."\n".$slide['body']),
            $this->slides,
        )));
    }

    /** Everything an operator will read, which on a carousel is more than the post. */
    public function reviewText(): string
    {
        return trim($this->text()."\n\n".$this->panelText());
    }

    public function isEmpty(): bool
    {
        return $this->text() === '';
    }

    /** Whether anything deterministic refuses it. */
    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /**
     * The same candidate with its verdicts attached.
     *
     * Scoring and guarding happen after parsing and are done by objects this
     * one does not depend on ({@see ChannelPostScore}, {@see StudioPostGuard}),
     * so they arrive here rather than being computed in the constructor. That
     * also keeps the class free of a playbook it would otherwise have to hold
     * only to answer one question.
     *
     * @param  list<GuardFinding>  $findings
     */
    public function judged(int $score, array $findings): self
    {
        return new self(
            angle: $this->angle,
            format: $this->format,
            segments: $this->segments,
            link: $this->link,
            chainReason: $this->chainReason,
            slides: $this->slides,
            visual: $this->visual,
            score: $score,
            findings: $findings,
        );
    }

    /**
     * What an operator is shown about how this draft was chosen (§7).
     *
     * The pool size and the losing scores are in here rather than only in the
     * logs because "the best of four" and "the only one" are different
     * sentences about the same draft, and the second one is what the Studio
     * used to produce silently.
     *
     * @param  list<int>  $scores  every candidate's score, in the order they were written
     * @return array<string, mixed>
     */
    public function selectionReport(ChannelPlaybook $playbook, array $scores, int $bar): array
    {
        return [
            'angle' => $this->angle,
            'score' => $this->score,
            'bar' => $bar,
            'cleared_bar' => $this->score >= $bar,
            'pool' => count($scores),
            'scores' => $scores,
            'channel' => $playbook->channel->value,
        ];
    }
}
