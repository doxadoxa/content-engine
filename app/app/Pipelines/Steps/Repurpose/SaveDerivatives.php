<?php

declare(strict_types=1);

namespace App\Pipelines\Steps\Repurpose;

use App\Enums\ContentItemType;
use App\Enums\SocialBand;
use App\Models\ContentItem;
use App\Pipelines\Core\AbstractStep;
use App\Pipelines\Core\StepContext;
use App\Pipelines\Core\StepResult;
use App\Pipelines\Exceptions\TerminalStepFailure;
use App\Pipelines\Steps\Generation\ResolvesUnit;
use Illuminate\Support\Str;

/**
 * The fan-in: write the derivatives, having checked they are derivative (§8.1).
 *
 * The check is the interesting half. §8.1 asks for "единый голос: производная
 * не может противоречить родителю в фактах — проверка на пересечение
 * сущностей", so a post that mentions none of the parent's entities is refused
 * rather than saved: it is about something else, and a social post about
 * something else does nothing for the parent's citability (§1's third
 * differentiator).
 *
 * What is saved is a {@see ContentItemType::SocialPost}. Until the social phase
 * gave the engine a type for it, this step wrote `Explainer` because that was
 * the nearest case that existed — which made "how many explainers has this
 * project published" unanswerable and, worse, handed a 300-character post the
 * schema.org type of an article. §3 of the social spec names removing that hack
 * as part of the type's purpose.
 */
class SaveDerivatives extends AbstractStep
{
    use ResolvesUnit;

    public static function key(): string
    {
        return 'save_derivatives';
    }

    /** @return list<string> */
    public function dependsOn(): array
    {
        return [WritePosts::key(), GenerateHero::key()];
    }

    public function handle(StepContext $context): StepResult
    {
        $unit = $this->unit($context);
        $parent = $context->output(LoadParent::key(), ParentPayload::class);
        $links = $context->output(LinkInternally::key(), LinksPayload::class);
        $written = $context->output(WritePosts::key(), DerivativesPayload::class);

        $saved = [];

        foreach ($written->posts as $channel => $body) {
            $overlap = DerivativeOverlap::share($parent->entities, $body);

            if (! DerivativeOverlap::meets($parent->entities, $body)) {
                throw new TerminalStepFailure(sprintf(
                    'The %s post shares only %d%% of the article\'s entities, so it is not derived from it.',
                    $channel,
                    DerivativeOverlap::percent($overlap),
                ));
            }

            $existing = $unit->derivatives()->where('channel_type', $channel)->first();

            $attributes = [
                'parent_id' => $unit->getKey(),
                'content_plan_id' => $unit->content_plan_id,
                'brand_brief_id' => $unit->brand_brief_id,
                'locale' => $unit->locale,
                'type' => ContentItemType::SocialPost,
                // §5's Derivative band, which is what this step *is*: one
                // supplier of six, capped at ≤1 a week. Written here rather
                // than left null because the governor counts published units
                // by `(project_id, social_band, published_at)` — an untagged
                // derivative is invisible to its own ceiling, and a budget that
                // only binds the bands somebody remembered to tag is not a
                // budget.
                'social_band' => SocialBand::Derivative,
                'title' => Str::limit($parent->title, 80, ''),
                'target_query' => $unit->target_query,
                // Inherited, both of them — exit criterion 2.
                'entities' => $parent->entities,
                'internal_links' => $links->links,
                'channel_type' => $channel,
                'body_markdown' => $body,
                'summary' => Str::limit($body, 200),
            ];

            if ($existing !== null) {
                $existing->forceFill($attributes)->save();
                $saved[$channel] = $existing->getKey();

                continue;
            }

            $derivative = ContentItem::query()->create([
                ...$attributes,
                'slug' => $unit->slug.'-'.$channel,
                // Its own group: a derivative is not another language of the
                // article, and putting it in the parent's locale group would
                // make it an hreflang alternate of it.
                'locale_group_id' => (string) Str::ulid(),
            ]);

            $saved[$channel] = $derivative->getKey();
        }

        $context->remember('repurpose.derivatives', count($saved));

        return StepResult::success(new DerivativesPayload($saved));
    }
}
