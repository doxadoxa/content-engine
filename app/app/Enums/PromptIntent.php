<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What somebody typing this prompt is trying to do.
 *
 * Kept because one visibility percentage hides the difference that matters. A
 * brand absent from "best cleaning service in Lisbon" is losing customers at
 * the moment they are choosing; a brand absent from "how do I clean a marble
 * floor" is losing a reader. The first is urgent and the second is content
 * strategy, and averaging them into a single number reports neither.
 */
enum PromptIntent: string
{
    case Buying = 'buying';
    case Comparison = 'comparison';
    case Learning = 'learning';

    public function label(): string
    {
        return match ($this) {
            self::Buying => 'Buying intent',
            self::Comparison => 'Comparison',
            self::Learning => 'Learning',
        };
    }

    /**
     * How the month's prompts are split, when a project has no opinion.
     *
     * Weighted toward buying and comparison because that is where being missing
     * costs money, but not exclusively: a brand cited in explainers is how
     * assistants come to trust it on the commercial questions.
     *
     * @return list<self>
     */
    public static function mix(int $count): array
    {
        $order = [self::Buying, self::Buying, self::Comparison, self::Comparison, self::Learning];

        $mix = [];

        for ($i = 0; $i < max(0, $count); $i++) {
            $mix[] = $order[$i % count($order)];
        }

        return $mix;
    }
}
