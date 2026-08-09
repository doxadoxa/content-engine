<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Ai\Contracts\ModelGateway;
use App\Ai\ModelRequest;
use App\Models\BrandBrief;
use App\Models\Project;

/**
 * The onboarding agent (§9.4): a conversation in, a Brand Brief out.
 *
 * Last on purpose, and §3.1 says why: by now four briefs have been written by
 * hand, so the agent gets worked examples as few-shot rather than guessing at
 * the shape of a good one. That is the whole reason this was not built first.
 *
 * The questions are fixed and the compiling is the model's job. A free-form
 * chat would produce a better transcript and a worse brief — the fields are
 * known, and what is hard is turning what somebody said into them.
 */
class BriefOnboarding
{
    /** @var list<array{key: string, question: string}> */
    public const array QUESTIONS = [
        ['key' => 'business', 'question' => 'What does the business do, and for whom?'],
        ['key' => 'audience', 'question' => 'Who reads this, and what do they already know?'],
        ['key' => 'voice', 'question' => 'Describe how you want to sound. Give an example sentence you would publish.'],
        ['key' => 'avoid', 'question' => 'What should never appear? Claims, topics, tones.'],
        ['key' => 'visual', 'question' => 'What should the pictures look like?'],
        ['key' => 'competitors', 'question' => 'Who else writes about this? Names or domains.'],
    ];

    public function __construct(private readonly ModelGateway $models) {}

    /**
     * @param  array<string, string>  $answers  keyed by question key
     */
    public function compile(Project $project, array $answers): BrandBrief
    {
        $transcript = [];

        foreach (self::QUESTIONS as $question) {
            $answer = trim($answers[$question['key']] ?? '');

            if ($answer !== '') {
                $transcript[] = "Q: {$question['question']}\nA: {$answer}";
            }
        }

        $response = $this->models->send(new ModelRequest(
            role: 'draft',
            instructions: $this->instructions(),
            prompt: implode("\n\n", $transcript),
        ));

        return BrandBrief::revise(
            $project,
            $this->parse($response->text),
            'Compiled by the onboarding agent.',
        );
    }

    /**
     * Few-shot from the briefs that already exist, which is the point of doing
     * this phase last (§3.1).
     */
    private function instructions(): string
    {
        $examples = BrandBrief::acrossProjects()
            ->where('is_active', true)
            ->limit(2)
            ->get()
            ->map(static fn (BrandBrief $brief): string => $brief->compileToPrompt())
            ->implode("\n\n---\n\n");

        return implode("\n\n", array_filter([
            'You turn an interview into a brand brief. Answer only with the labelled sections below, '
                .'each on its own line, list items separated by " | ":',
            'POSITIONING: ...'."\n".'AUDIENCE: ...'."\n".'TONE: ...'."\n".'VISUAL: ...'."\n"
                .'FORBIDDEN: a | b'."\n".'LIKED: a | b'."\n".'DISLIKED: a | b'."\n".'COMPETITORS: a | b',
            'Use the interviewee\'s own words where you can. Never invent a fact about the business.',
            $examples === '' ? null : "Briefs of this quality, for other businesses:\n\n{$examples}",
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $text): array
    {
        $fields = [
            'POSITIONING' => 'positioning',
            'AUDIENCE' => 'audience',
            'TONE' => 'tone',
            'VISUAL' => 'visual_language',
        ];

        $lists = [
            'FORBIDDEN' => 'forbidden_topics',
            'LIKED' => 'examples_liked',
            'DISLIKED' => 'examples_disliked',
            'COMPETITORS' => 'competitors',
        ];

        $brief = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            if (preg_match('/^([A-Z]+):\s*(.*)$/u', trim($line), $matches) !== 1) {
                continue;
            }

            [$label, $value] = [$matches[1], trim($matches[2])];

            if (isset($fields[$label])) {
                $brief[$fields[$label]] = $value;

                continue;
            }

            if (isset($lists[$label])) {
                $brief[$lists[$label]] = array_values(array_filter(
                    array_map(trim(...), explode('|', $value)),
                    static fn (string $item): bool => $item !== '',
                ));
            }
        }

        return $brief;
    }
}
