<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Generation;

use App\Enums\ContentItemState;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Support\Content\SafeMarkdown;
use Illuminate\Support\Str;

/**
 * The fan-in: write everything onto the unit and move it (§5.2, §5.4).
 *
 * Two rules live here, and both are in one place on purpose — this is the only
 * step that changes what an operator sees.
 *
 * **A YMYL unit with a failed fact-check does not reach `draft`.** §5.2 makes
 * that a requirement rather than a setting, and it is enforced by failing the
 * run: the unit stays in `generating` with its findings recorded, so nothing is
 * quietly reviewable that should not be. Terminal, because the same text will
 * fail the same check.
 *
 * **Approve mode is the default.** §5.4 and §1 make auto-publish a privilege a
 * pipeline earns on a specific project, so this step never publishes; the most
 * it does is leave an approved unit for the delivery of phase 6.
 */
class FinaliseDraft extends AbstractStep
{
    use ResolvesUnit;

    public function __construct(private readonly SafeMarkdown $markdown) {}

    public static function key(): string
    {
        return 'finalise_draft';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [
            FactCheck::key(),
            BuildGeoLayer::key(),
            CoverEntities::key(),
            // The body saved here is the one whose citations were checked, so
            // this has to wait for the check rather than race it.
            VerifyLinks::key(),
            LinkToSite::key(),
        ];
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);

        $brief = $context->output(CompileBrief::key(), BriefContextPayload::class);
        $outline = $context->output(WriteOutline::key(), OutlinePayload::class);
        $draft = $context->output(WriteDraft::key(), DraftPayload::class);
        $check = $context->output(FactCheck::key(), FactCheckPayload::class);
        $geo = $context->output(BuildGeoLayer::key(), GeoPayload::class);
        $entities = $context->output(CoverEntities::key(), EntityCoveragePayload::class);

        // The body that is stored is the one whose citations were checked. The
        // verifier skips when the draft cites nothing, so the original stands
        // in that case — see StepResult::skip().
        $markdown = $context->hasOutput(VerifyLinks::key())
            ? $context->output(VerifyLinks::key(), VerifiedLinksPayload::class)->markdown
            : $draft->markdown;

        // Written before the gate below, so a blocked unit still carries the
        // findings and the body an operator needs to fix it.
        $written = $this->writtenTitle($markdown);

        $unit->forceFill([
            ...($written === null ? [] : [
                'title' => $written,
                'slug' => $this->slugFor($unit, $written),
            ]),
            'outline' => $outline->sections,
            'body_markdown' => $markdown,
            'body_html' => $this->markdown->render($markdown),
            'summary' => $draft->summary,
            'json_ld' => $geo->jsonLd,
            'faq_json_ld' => $geo->faqJsonLd,
            'quotable_blocks' => $geo->quotableBlocks,
            'entity_coverage' => $entities->coverage,
            'factcheck' => $check->toArray(),
            'author' => $brief->author,
            'image_anchors' => $draft->imageAnchors,
        ])->save();

        if ($check->required && ! $check->passed) {
            throw new TerminalStepFailure(sprintf(
                'The fact-check did not pass and this project is YMYL, so the unit may not become a draft. %d finding(s): %s',
                count($check->findings),
                implode(' | ', array_slice($check->findings, 0, 3)),
            ));
        }

        // Not unconditionally: regenerating a unit that is already a draft is
        // an ordinary thing to want, and `draft → draft` is not an edge on the
        // map — calling it would fail the run on the last step after paying for
        // every model call. A unit in `refreshing` does have the edge and takes
        // it; one in `published` does not, and should have been sent through
        // startRefresh() first, so letting that throw is correct.
        if ($unit->state !== ContentItemState::Draft) {
            $unit->markDrafted();
        }

        $context->remember('generation.unit_id', $unit->getKey());
        $context->remember('generation.autopublish', $context->project->autopublish);

        return StepResult::success(new DraftPayload(
            markdown: $draft->markdown,
            summary: $draft->summary,
            imageAnchors: $draft->imageAnchors,
        ));
    }

    /**
     * The article's own headline, lifted off its first heading.
     *
     * The title was `Str::headline($keyword)` — the search query in title case,
     * set when the idea was stored and never touched again. That is how a piece
     * ends up called "Empresa De Limpeza Em Lisboa" rather than something a
     * person would click, and why every locale of it carried the source
     * language's words: the query is in one language and the article may be in
     * another.
     *
     * Taken from the body rather than asked for separately, because the writer
     * has already written it. A model call to name an article it just finished
     * would be paying twice for the same sentence.
     */
    private function writtenTitle(string $markdown): ?string
    {
        if (preg_match('/^#\s+(.+?)\s*$/mu', $markdown, $matches) !== 1) {
            return null;
        }

        $title = trim($matches[1]);

        // Guarded either side: an empty heading leaves the old title alone, and
        // one longer than the column would truncate mid-word on save.
        return $title === '' || mb_strlen($title) > 255 ? null : $title;
    }

    /**
     * A slug in the article's own language, unique within its locale.
     *
     * `(project, locale, slug)` is unique, and a locale variant used to inherit
     * the source slug verbatim — so the English edition of a Portuguese article
     * lived at `/en/journal/portas-brancas-sujam`. The words in a URL were the
     * one part of a translated page that never got translated.
     */
    private function slugFor(ContentItem $unit, string $title): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            return $unit->slug;
        }

        $candidate = $base;
        $suffix = 2;

        while (ContentItem::query()
            ->where('locale', $unit->locale)
            ->where('slug', $candidate)
            ->whereKeyNot($unit->getKey())
            ->exists()
        ) {
            $candidate = $base.'-'.$suffix++;
        }

        return $candidate;
    }
}
