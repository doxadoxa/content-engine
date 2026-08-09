<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\SocialDraft;

use App\Enums\SocialBand;
use App\Models\BrandBrief;
use App\Models\ContentItem;
use App\Models\Signal;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Generation\ResolvesUnit;
use App\Pipelines\Steps\SocialEngage\LoadConversation;
use App\Social\Governor;
use App\Support\Social\ProjectVocabulary;
use Illuminate\Support\Str;

/**
 * The brief, the signal, the corpus and the parent — read once, on the cheap
 * queue (§4.3).
 *
 * Everything the pool needs is resolved here, for the reason
 * {@see LoadConversation} gives: the step that
 * spends money should be one provider call and nothing else, because it holds a
 * worker from the smaller pool while it runs.
 *
 * **The week's verdict is part of the context.** The bar a candidate has to
 * clear is a fact about the week — §4.3's "trailing reply rate ниже порога →
 * срезает частоту и **поднимает планку отбора**" — so it is read alongside the
 * brief and carried forward, rather than re-derived after the pool exists. Two
 * reads of the governor across one run is two chances for the bar to move under
 * the candidates it is judging.
 *
 * **A slot past its window is refused before anything is spent.** §5 kills a
 * reactive draft that missed its hour rather than publishing it late, and the
 * cheapest possible place to notice is before the first of eight model calls.
 */
class LoadContext extends AbstractStep
{
    use ResolvesUnit;

    /**
     * How much of a parent article the drafting prompt is shown.
     *
     * Enough to know what the piece argued, and nowhere near the whole thing: a
     * derivative is a 500-character post, and paying to put two thousand words
     * into eight prompts buys tokens rather than posts.
     */
    private const int PARENT_EXCERPT_CHARS = 1_500;

    public function __construct(
        private readonly ProjectVocabulary $vocabulary,
        private readonly Governor $governor,
    ) {}

    public static function key(): string
    {
        return 'load_context';
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);

        if (! $unit->isSocial()) {
            // Terminal: an article routed into the social pipeline does not
            // become a post by being retried, and drafting one as if it were
            // would overwrite a body somebody is waiting to publish.
            throw new TerminalStepFailure(
                "Unit `{$unit->slug}` is not a social post, so there is no slot here to draft."
            );
        }

        if ($unit->hasExpired()) {
            // Not a failure. §5: "черновик, не попавший в окно, убивается, а не
            // публикуется позже", and a run that arrives after the window has
            // nothing left to do that would be right.
            return StepResult::skip(
                'This slot\'s window has already closed, and §5 does not publish a reaction late.'
            );
        }

        $brief = BrandBrief::activeFor($context->project);

        // Looked up rather than reached through the relation: this application
        // refuses lazy loads, and `$unit->signal` on a row loaded by id is one.
        $signal = $unit->signal_id === null
            ? null
            : Signal::query()->find($unit->signal_id);

        $parent = $unit->parent_id === null
            ? null
            : ContentItem::query()->find($unit->parent_id);

        return StepResult::success(new SlotContextPayload(
            slotId: (string) $unit->getKey(),
            band: $unit->social_band ?? SocialBand::Question,
            title: $unit->title,
            brief: $brief?->compileToPrompt() ?? '',
            forbiddenTopics: $brief === null ? [] : $brief->forbidden_topics,
            vocabulary: $this->vocabulary->entities($context->project),
            // Only the bands that speak from the business get the facts. §4.3's
            // sibling rule in generation is the same one: handing a model the
            // price list on every unit is how prices end up in posts that never
            // needed them.
            originalData: $unit->social_band === SocialBand::OwnData
                ? $context->project->original_data
                : [],
            signalTitle: $signal?->title,
            signalUrl: $signal?->url,
            parentId: $parent?->getKey(),
            parentTitle: $parent?->title,
            // The parent's entities and not the post's: §4.3's rule is
            // "пересечение ≥34% с родителем", so what the guard measures
            // against is what the article was about.
            parentEntities: $parent === null ? [] : $parent->entities,
            parentBody: $parent === null ? null : $this->excerpt($parent),
            verdict: $this->governor->verdict($context->project, $unit->slot_at),
        ));
    }

    /** The opening of the parent, which is where an article says what it is. */
    private function excerpt(ContentItem $parent): ?string
    {
        $body = trim((string) ($parent->body_markdown ?? $parent->summary ?? ''));

        return $body === '' ? null : Str::limit($body, self::PARENT_EXCERPT_CHARS);
    }
}
