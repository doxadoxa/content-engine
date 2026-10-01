<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Content\AiDisclosure;
use App\Models\Project;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The line that says AI helped write an article: when it appears, in which
 * language, and that it appears once.
 */
final class AiDisclosureTest extends TestCase
{
    #[Test]
    public function it_says_nothing_unless_the_project_asks(): void
    {
        $project = new Project(['name' => 'Courtly', 'ai_disclosure' => false]);

        $this->assertNull(app(AiDisclosure::class)->lineFor($project, 'en'));
        $this->assertSame('Body.', app(AiDisclosure::class)->appendTo('Body.', $project, 'en'));
    }

    #[Test]
    public function it_is_written_in_the_articles_language(): void
    {
        $project = new Project(['name' => 'Courtly', 'ai_disclosure' => true]);
        $disclosure = app(AiDisclosure::class);

        $this->assertSame(
            'Este artigo foi escrito com a ajuda de IA. Responsável pelo conteúdo: Courtly.',
            $disclosure->lineFor($project, 'pt-PT'),
        );
        $this->assertStringStartsWith('Dieser Artikel', (string) $disclosure->lineFor($project, 'de_AT'));
        // No sentence for this language: English, rather than nothing.
        $this->assertStringStartsWith('This article was written', (string) $disclosure->lineFor($project, 'ja'));
    }

    #[Test]
    public function it_is_added_once_however_often_the_article_is_finished(): void
    {
        $project = new Project(['name' => 'Courtly', 'ai_disclosure' => true]);
        $disclosure = app(AiDisclosure::class);

        $once = $disclosure->appendTo("## Heading\n\nBody.\n", $project, 'en');
        $twice = $disclosure->appendTo($once, $project, 'en');

        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, 'with the help of AI'));
        $this->assertStringContainsString("Body.\n\n---\n\n*This article", $once);
    }

    #[Test]
    public function a_brand_name_cannot_turn_into_markup(): void
    {
        $project = new Project(['name' => 'Star*Fish [Ltd]', 'ai_disclosure' => true]);

        $this->assertStringContainsString(
            'Star\\*Fish \\[Ltd\\]',
            (string) app(AiDisclosure::class)->lineFor($project, 'en'),
        );
    }
}
