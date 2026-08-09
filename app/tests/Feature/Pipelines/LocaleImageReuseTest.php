<?php

declare(strict_types=1);

namespace Tests\Feature\Pipelines;

use App\Enums\AssetRole;
use App\Media\Contracts\ImageGenerationProvider;
use App\Media\FakeImageGeneration;
use App\Media\HeroImage;
use App\Models\Asset;
use App\Models\ContentItem;
use App\Models\Project;
use App\Support\Tenancy\CurrentProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * One topic, three languages, one set of pictures.
 *
 * §2 makes a locale a unit of its own — planned, dated and costed like any
 * other — and that was silently true of the images too. It should not be: a
 * photograph of a post-renovation clean is the same photograph whatever
 * language the article around it is in, and only the alt text is not.
 *
 * What it cost. On 2026-08-09 the Cleaning Point project had twelve generated
 * files for one article — a hero and three inline pictures in each of pt, en
 * and ru, every one of them a separate paid generation of the same subject.
 * Three times the largest per-article cost in the product, for nothing a reader
 * could see.
 */
final class LocaleImageReuseTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private FakeImageGeneration $images;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create(['default_locale' => 'pt-PT']);
        app(CurrentProject::class)->set($this->project);

        /** @var FakeImageGeneration $images */
        $images = app(ImageGenerationProvider::class);
        $this->images = $images;
    }

    #[Test]
    public function a_second_locale_hangs_the_first_locales_hero_rather_than_buying_one(): void
    {
        $hero = app(HeroImage::class);

        $portuguese = $this->unit('pt-PT', 'Limpeza pós-obra em Lisboa');
        $english = $this->addLocale($portuguese, 'en-GB', 'Post-renovation cleaning in Lisbon');

        $first = $hero->for($portuguese, $portuguese->title, 'Uma casa pronta para viver.');
        $second = $hero->for($english, $english->title, 'A home ready to live in.');

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        // One generation for two articles.
        $this->assertCount(1, $this->images->prompts());
        $this->assertSame(0, $second['cost'], 'A borrowed picture is free.');

        // The same file, and deliberately not the same row: the asset belongs
        // to the unit it illustrates, and the publish payload reads it per unit.
        $this->assertSame($first['asset']->path, $second['asset']->path);
        $this->assertNotSame($first['asset']->getKey(), $second['asset']->getKey());
        $this->assertSame($english->getKey(), $second['asset']->content_item_id);

        // Alt is the one thing that is language-specific, and it lives on the
        // row rather than on the file — which is what makes sharing possible.
        $this->assertSame($english->title, $second['asset']->alt);
    }

    #[Test]
    public function section_pictures_are_borrowed_by_position_and_re_labelled(): void
    {
        $hero = app(HeroImage::class);

        $portuguese = $this->unit('pt-PT', 'Limpeza pós-obra');
        $english = $this->addLocale($portuguese, 'en-GB', 'Post-renovation cleaning');

        foreach (['Produtos e métodos', 'Quanto tempo demora'] as $position => $heading) {
            $hero->inline($portuguese, $heading, 'limpeza', position: $position);
        }

        $borrowed = [];

        foreach (['Products and methods', 'How long it takes'] as $position => $heading) {
            $made = $hero->inline($english, $heading, 'cleaning', position: $position);

            $this->assertNotNull($made);
            $borrowed[] = $made['asset'];
        }

        $this->assertCount(2, $this->images->prompts(), 'Only the first locale should have paid.');

        $originals = Asset::query()
            ->where('content_item_id', $portuguese->getKey())
            ->where('role', AssetRole::Inline)
            ->orderBy('id')
            ->get();

        // Position for position, in the order the sections appear — the
        // English second section gets the picture the Portuguese second
        // section got, not the first one again.
        $this->assertSame($originals[0]->path, $borrowed[0]->path);
        $this->assertSame($originals[1]->path, $borrowed[1]->path);
        $this->assertNotSame($borrowed[0]->path, $borrowed[1]->path);

        // Anchored and described in *this* language, so it lands under the
        // right heading and reads correctly to a screen reader.
        $this->assertSame('products-and-methods', $borrowed[0]->anchor);
        $this->assertSame('Products and methods', $borrowed[0]->alt);
    }

    #[Test]
    public function a_locale_with_more_sections_than_its_sibling_still_gets_pictures(): void
    {
        $hero = app(HeroImage::class);

        $portuguese = $this->unit('pt-PT', 'Limpeza pós-obra');
        $english = $this->addLocale($portuguese, 'en-GB', 'Post-renovation cleaning');

        // The Portuguese article only ran to one illustrated section.
        $hero->inline($portuguese, 'Produtos e métodos', 'limpeza', position: 0);

        $first = $hero->inline($english, 'Products and methods', 'cleaning', position: 0);
        $second = $hero->inline($english, 'How long it takes', 'cleaning', position: 1);

        $this->assertNotNull($first);
        $this->assertNotNull($second);

        // The borrowable one was borrowed; the section with nothing to borrow
        // was drawn rather than filled with a repeat of the picture above it.
        $this->assertSame(0, $first['cost']);
        $this->assertSame(FakeImageGeneration::COST_MICROS, $second['cost']);
        $this->assertNotSame($first['asset']->path, $second['asset']->path);
        $this->assertCount(2, $this->images->prompts());
    }

    #[Test]
    public function another_topic_is_not_a_source_of_pictures(): void
    {
        $hero = app(HeroImage::class);

        $windows = $this->unit('pt-PT', 'Limpeza de janelas');
        $renovation = $this->unit('en-GB', 'Post-renovation cleaning');

        $hero->for($windows, $windows->title, null);
        $hero->for($renovation, $renovation->title, null);

        // Two unrelated units, so two pictures. Sharing is scoped to the locale
        // group, which is one subject in several languages — not to the project.
        $this->assertCount(2, $this->images->prompts());
    }

    private function unit(string $locale, string $title): ContentItem
    {
        return ContentItem::factory()->create([
            'locale' => $locale,
            'title' => $title,
            'target_query' => 'limpeza pos obra',
        ]);
    }

    private function addLocale(ContentItem $unit, string $locale, string $title): ContentItem
    {
        return $unit->addLocale($locale, $unit->slug.'-'.strtolower(substr($locale, 0, 2)), $title);
    }
}
