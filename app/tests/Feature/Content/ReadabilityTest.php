<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Content\Readability;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Reading level, and the languages it may not be claimed for.
 */
final class ReadabilityTest extends TestCase
{
    #[Test]
    public function it_refuses_to_score_a_language_it_was_not_built_for(): void
    {
        $readability = new Readability;

        $ukrainian = 'Прибирання квартири після ремонту триває від чотирьох до восьми годин, '
            .'залежно від площі та стану поверхонь у приміщенні.';

        // Ukrainian is close enough to Russian that Oborneva's constants would
        // produce a plausible number, and plausible is the failure mode this
        // guard exists for: there is no published adaptation, so there is no
        // honest answer.
        $this->assertNull($readability->measure($ukrainian, 'uk'));
        $this->assertFalse($readability->supports('uk'));
        $this->assertFalse($readability->supports(null));
    }

    #[Test]
    public function each_supported_language_is_scored_on_its_own_published_scale(): void
    {
        $readability = new Readability;

        $portuguese = 'A limpeza profunda de um apartamento em Lisboa demora entre duas e '
            .'quatro horas. O tempo muda com a dimensão. As superfícies contam muito.';

        $russian = 'Глубокая уборка квартиры в Лиссабоне занимает от двух до четырёх часов. '
            .'Время зависит от площади. Состояние поверхностей тоже важно.';

        $pt = $readability->measure($portuguese, 'pt-PT');
        $ru = $readability->measure($russian, 'ru');

        $this->assertNotNull($pt);
        $this->assertNotNull($ru);

        // Martins (1996) and Oborneva (2006) both answer on Flesch Reading
        // Ease, where higher is easier — the opposite direction from the
        // English grade. Nothing may compare the two numbers to each other.
        $this->assertSame('ease', $pt['scale']);
        $this->assertSame('ease', $ru['scale']);
        $this->assertSame('grade', $readability->measure('The door was clean. It stayed dry. We left.', 'en')['scale']);

        // The passive test is a fact about English grammar; reporting 0% here
        // would read as a clean pass rather than an unasked question.
        $this->assertNull($pt['passive_ratio']);
        $this->assertNull($ru['passive_ratio']);
    }

    #[Test]
    public function comfort_follows_the_direction_of_the_scale(): void
    {
        $readability = new Readability;

        // Getting this backwards would pass exactly the prose it is meant to
        // catch, because the two scales run opposite ways.
        $this->assertTrue($readability->isComfortable(
            ['scale' => 'ease', 'language' => 'pt', 'grade' => 70.0, 'sentences' => 5, 'words' => 50, 'average_sentence' => 10.0, 'passive_ratio' => null],
        ));

        $this->assertFalse($readability->isComfortable(
            ['scale' => 'ease', 'language' => 'pt', 'grade' => 20.0, 'sentences' => 5, 'words' => 50, 'average_sentence' => 10.0, 'passive_ratio' => null],
        ));

        $this->assertTrue($readability->isComfortable(
            ['scale' => 'grade', 'language' => 'en', 'grade' => 8.0, 'sentences' => 5, 'words' => 50, 'average_sentence' => 10.0, 'passive_ratio' => 0.0],
        ));

        $this->assertFalse($readability->isComfortable(
            ['scale' => 'grade', 'language' => 'en', 'grade' => 18.0, 'sentences' => 5, 'words' => 50, 'average_sentence' => 10.0, 'passive_ratio' => 0.0],
        ));
    }

    #[Test]
    public function the_comfort_floor_is_the_one_fitted_to_each_language(): void
    {
        $readability = new Readability;

        $row = static fn (string $language, float $value): array => [
            'scale' => 'ease', 'language' => $language, 'grade' => $value,
            'sentences' => 5, 'words' => 50, 'average_sentence' => 10.0, 'passive_ratio' => null,
        ];

        // Six published articles from a real site in this domain, in both
        // languages — translations of each other — ran 28.0–47.8 in Portuguese
        // and 24.4–37.7 in Russian. One shared floor of 50 failed all twelve,
        // and the eleven-point gap is the formulas' constants rather than the
        // writing.
        $this->assertTrue($readability->isComfortable($row('ru', 30.7)));
        $this->assertFalse($readability->isComfortable($row('pt', 30.7)));

        // And each still catches its own hard tail.
        $this->assertFalse($readability->isComfortable($row('ru', 20.0)));
        $this->assertTrue($readability->isComfortable($row('pt', 41.9)));
    }

    #[Test]
    public function the_detail_line_uses_the_words_of_its_own_scale(): void
    {
        $readability = new Readability;

        // "grade 62" would be worse than printing nothing at all.
        $this->assertStringContainsString('reading ease', $readability->describe(
            ['scale' => 'ease', 'language' => 'pt', 'grade' => 62.0, 'sentences' => 5, 'words' => 50, 'average_sentence' => 10.0, 'passive_ratio' => null],
        ));

        $this->assertStringContainsString('grade 10.4', $readability->describe(
            ['scale' => 'grade', 'language' => 'en', 'grade' => 10.4, 'sentences' => 5, 'words' => 50, 'average_sentence' => 10.0, 'passive_ratio' => 0.0],
        ));
    }

    #[Test]
    public function english_is_scored(): void
    {
        $readability = new Readability;

        $text = 'A deep clean of a flat takes two to four hours. The time depends on the '
            .'size of the rooms and the state of the surfaces. A team of two will finish '
            .'sooner than one person working alone.';

        $measured = $readability->measure($text, 'en');

        $this->assertNotNull($measured);
        $this->assertGreaterThan(0.0, $measured['grade']);
        $this->assertSame(3, $measured['sentences']);
    }

    #[Test]
    public function short_direct_prose_is_not_marked_down_for_being_easy(): void
    {
        $readability = new Readability;

        // Exactly what the house style asks for: short sentences, fragments,
        // plain words. A floor on the grade would fail writing for doing what
        // it was told, so there is a ceiling and no floor.
        $plain = 'A deep clean takes about three hours. Bathrooms take the longest. '
            .'We bring our own cloths and sprays. If you have marble, tell us first, '
            .'because it needs a different product. Most flats need one visit a week.';

        $measured = $readability->measure($plain, 'en');

        $this->assertNotNull($measured);
        $this->assertLessThan(6.0, $measured['grade']);
        $this->assertTrue($readability->isComfortable($measured));
    }

    #[Test]
    public function prose_nobody_can_follow_fails(): void
    {
        $readability = new Readability;

        $dense = 'The implementation of comprehensive environmental remediation methodologies '
            .'necessitates consideration of the interdependencies between substrate '
            .'characteristics, atmospheric particulate concentrations, and the operational '
            .'parameters governing the deployment of specialised decontamination apparatus '
            .'within residential accommodation environments.';

        $measured = $readability->measure($dense, 'en');

        $this->assertNotNull($measured);
        $this->assertFalse($readability->isComfortable($measured));
    }

    #[Test]
    public function markdown_furniture_is_not_counted_as_prose(): void
    {
        $readability = new Readability;

        $withMarkdown = "## A heading that is not a sentence\n\n"
            ."![An image](https://example.com/a.webp)\n\n"
            .'The cleaner arrives at nine. The work takes two hours. '
            .'We [publish our prices](https://example.com/prices) in full.';

        $measured = $readability->measure($withMarkdown, 'en');

        $this->assertNotNull($measured);

        // The heading and the image alt text are not sentences a reader reads
        // as prose, and counting them moves the grade for no reason.
        $this->assertSame(3, $measured['sentences']);
    }

    #[Test]
    public function passive_voice_is_noticed(): void
    {
        $readability = new Readability;

        $passive = 'The floors were cleaned by the team. The windows are washed twice a year. '
            .'The keys were given to the concierge. The invoice is sent afterwards.';

        $measured = $readability->measure($passive, 'en');

        $this->assertNotNull($measured);
        $this->assertGreaterThan(0.5, $measured['passive_ratio']);
    }
}
