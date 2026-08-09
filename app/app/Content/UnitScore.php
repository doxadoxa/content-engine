<?php

declare(strict_types=1);

namespace App\Content;

use App\Models\ContentItem;

/**
 * "Is this fit to publish", asked of a unit rather than of an article.
 *
 * Since §3 a content unit is one of two things and they are graded by two
 * different lists: {@see ArticleScore} reads a page, {@see PostScore} reads
 * §2's format rules. The approvals queue holds both and has to ask the question
 * once, so the branch lives here — in one place, next to both answers — rather
 * than as a ternary in every screen that scores something.
 *
 * The two return the same shape deliberately. A caller that had to know which
 * kind of score it was holding would be a caller that has to know what kind of
 * unit it is holding, and then the approval screen grows two of everything —
 * which is exactly the second habit §7's "одна сводка и пять минут" rules out.
 */
class UnitScore
{
    public function __construct(
        private readonly ArticleScore $articles,
        private readonly PostScore $posts,
    ) {}

    /**
     * @return array{
     *     score: int,
     *     publishable: bool,
     *     blocking: list<string>,
     *     checks: list<array{key: string, label: string, ok: bool, detail: string, severity: string}>,
     * }
     */
    public function for(ContentItem $item): array
    {
        return $item->isSocial()
            ? $this->posts->for($item)
            : $this->articles->for($item);
    }
}
