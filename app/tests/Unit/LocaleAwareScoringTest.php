<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Content\HouseStyle;
use App\Content\Words;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Scoring an article that is not in English.
 *
 * The first Russian article this engine wrote scored 55 against an English
 * article's 100, and almost none of the gap was about the writing. Its
 * sentences counted as zero words, so the rhythm check saw an empty document;
 * the banned-word list found nothing and reported a clean pass it had not
 * earned; and the limitations check — the guide's highest-leverage rule, and a
 * critical one — looked for English phrases in Cyrillic prose and failed it.
 *
 * A score is only useful if it is comparable, so the rule here is: measure what
 * applies, say what does not, and never report an absent check as a pass.
 */
final class LocaleAwareScoringTest extends TestCase
{
    // --------------------------------------------------------- word counting

    /** @return iterable<string, array{string, int}> */
    public static function passages(): iterable
    {
        yield 'english' => ['The door was clean and dry.', 6];
        yield 'portuguese accents' => ['A limpeza pós-obra ficou impecável.', 5];
        yield 'cyrillic' => ['Чистая проза здесь для объёма.', 5];
        yield 'ukrainian' => ['Прибирання після ремонту коштує дорожче.', 5];

        // What str_word_count gets right and must keep getting right.
        yield 'apostrophes stay inside a word' => ["It doesn't fit.", 3];
        yield 'hyphens stay inside a word' => ['A post-renovation clean.', 3];

        // An em dash used as a bullet is punctuation, not a word.
        yield 'stray dashes are not words' => ['One — two — three', 3];
        yield 'digits count' => ['Wait 20 minutes.', 3];
        yield 'empty' => ['', 0];
    }

    #[Test]
    #[DataProvider('passages')]
    public function it_counts_words_in_any_script(string $text, int $expected): void
    {
        $this->assertSame($expected, Words::count($text));
    }

    #[Test]
    public function a_cyrillic_article_is_not_reported_as_almost_empty(): void
    {
        $body = str_repeat('Чистая проза здесь для объёма текста статьи. ', 100);

        // str_word_count is locale-dependent: different PHP/OS combinations
        // have returned both zero and wildly inflated values for this text.
        // The application counter is the stable contract we care about.
        $this->assertSame(700, Words::count($body));
    }

    // ------------------------------------------------------------ the rhythm

    #[Test]
    public function rhythm_is_measurable_in_cyrillic(): void
    {
        $style = app(HouseStyle::class);

        // Deliberately lumpy: two-word sentences beside long ones, which is
        // what §3.1 asks for and what the check exists to reward.
        $varied = 'Дверь чистая. Мы обычно начинаем с пыли, потому что она оседает на всё '
            .'остальное и портит работу, если её оставить напоследок. Просто. Затем идёт влажная '
            .'уборка всех поверхностей, включая плинтусы и подоконники, которые чаще всего '
            .'пропускают. Быстро. Плитку моем отдельно. После ремонта требуется другой подход, '
            .'потому что строительная пыль тяжелее и въедается в швы намного сильнее обычной. '
            .'Это занимает время. Мы проверяем результат при дневном свете.';

        // Before the fix every one of these sentences counted as zero words, so
        // the check found fewer than eight sentences and failed the article for
        // uniformity it had never measured.
        $this->assertTrue($style->rhythmIsVaried($varied));
    }

    // ------------------------------------------------------- what applies

    #[Test]
    public function the_english_word_lists_do_not_run_on_other_languages(): void
    {
        $style = app(HouseStyle::class);

        // Every listed language is checked — against its own list. An English
        // tell sitting in Portuguese prose is not a Portuguese tell, and
        // flagging it would be judging one language by another's habits.
        $this->assertSame([], $style->violations('Vamos delve moreover na limpeza.', 'pt-PT'));
        $this->assertNotSame([], $style->violations('We delve, moreover, into it.', 'en'));

        $this->assertTrue($style->checksVocabulary('en-GB'));
        $this->assertTrue($style->checksVocabulary('pt-PT'));
        $this->assertTrue($style->checksVocabulary('ru'));
    }

    #[Test]
    public function the_em_dash_budget_does_not_apply_where_the_dash_is_grammar(): void
    {
        $style = app(HouseStyle::class);

        // Correct Russian, five times over: тире stands in for the dropped
        // copula, so this is what a well-written article looks like rather than
        // a stylistic tic. A universal budget of two failed nearly every
        // article in the language for obeying its own punctuation.
        $cyrillic = 'Уборка — это просто. Пыль — главный враг. Плитка — отдельная работа. '
            .'Вода — не всегда помощник. Результат — чистый дом.';

        // Checked and clean, not unmeasured: Russian has its own word list, so
        // the article was examined — the dash simply is not a fault in it.
        $this->assertSame([], $style->violations($cyrillic, 'ru'));

        // Still enforced where the guide's claim actually holds.
        $english = str_repeat('This is a thing — and another thing. ', 4);

        $this->assertNotSame([], $style->violations($english, 'en'));
    }

    #[Test]
    public function semicolons_are_judged_only_where_the_register_argument_holds(): void
    {
        $style = app(HouseStyle::class);

        // §2.2 says the semicolon reads as formal-register filler in commercial
        // prose, and that is a claim about English. Russian expository writing
        // uses them freely and correctly — the first Russian article this
        // engine wrote had four and was failed on a *critical* check for it.
        $this->assertSame([], $style->violations('Первое; второе; третье; четвёртое.', 'ru'));

        $this->assertNotSame([], $style->violations('One thing; another thing; a third.', 'en'));

        // Portuguese keeps the em-dash budget but not the semicolon rule.
        $this->assertSame([], $style->violations('Primeiro; segundo; terceiro; quarto.', 'pt-PT'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function tells(): iterable
    {
        // Each language's own habits, not translations of the English ones. A
        // model writing Russian never reaches for "delve"; it reaches for
        // "важно отметить".
        yield 'english' => ['en', 'Moreover, we should delve into the realm of cleaning.'];
        yield 'portuguese' => ['pt-PT', 'Além disso, vale ressaltar que no mundo atual a limpeza importa.'];
        yield 'russian' => ['ru', 'Важно отметить, что в современном мире уборка играет ключевую роль.'];
        yield 'ukrainian' => ['uk', 'Важливо зазначити, що у сучасному світі прибирання відіграє ключову роль.'];
    }

    #[Test]
    #[DataProvider('tells')]
    public function each_language_is_judged_by_its_own_tells(string $locale, string $body): void
    {
        $this->assertNotSame([], app(HouseStyle::class)->violations($body, $locale));
    }

    /** @return iterable<string, array{string, string}> */
    public static function symmetries(): iterable
    {
        yield 'english' => ['en', 'This is not just a clean, but a reset of the whole flat.'];
        yield 'portuguese' => ['pt-PT', 'Não apenas uma limpeza, mas também um recomeço para a casa.'];
        yield 'russian' => ['ru', 'Это не просто уборка, это полный сброс квартиры.'];
        yield 'ukrainian' => ['uk', 'Це не просто прибирання, це повне оновлення квартири.'];
    }

    #[Test]
    #[DataProvider('symmetries')]
    public function the_symmetrical_shapes_are_caught_in_each_language(string $locale, string $body): void
    {
        $this->assertNotSame([], app(HouseStyle::class)->violations($body, $locale));
    }

    #[Test]
    public function ordinary_prose_in_each_language_is_left_alone(): void
    {
        $style = app(HouseStyle::class);

        // The risk of a word list is the false positive, and here it fails an
        // article on a *critical* check. Plain, correct sentences in each
        // language have to come back clean.
        $this->assertSame([], $style->violations('We start with the dust and work down to the floor.', 'en'));
        $this->assertSame([], $style->violations('Começamos pelo pó e descemos até ao chão.', 'pt-PT'));
        $this->assertSame([], $style->violations('Мы начинаем с пыли и спускаемся к полу.', 'ru'));
        $this->assertSame([], $style->violations('Ми починаємо з пилу і спускаємося до підлоги.', 'uk'));
    }

    #[Test]
    public function a_language_with_no_list_is_unmeasured_not_passed(): void
    {
        $style = app(HouseStyle::class);

        // Null, not []. An empty violation list means "checked and clean"; a
        // language none of the rules can speak to has not been checked at all,
        // and the difference is the whole point of this file.
        $this->assertNull($style->violations('Sadece bir metin.', 'tr'));
        $this->assertFalse($style->checksVocabulary('tr'));

        $this->assertSame([], $style->violations('Just text.', 'en'));
        $this->assertTrue($style->checksVocabulary('ru'));
    }

    // ------------------------------------------- what the writer is told

    #[Test]
    public function the_writer_is_told_the_same_list_it_is_checked_against(): void
    {
        $style = app(HouseStyle::class);

        // The two halves used to disagree: a Russian draft was told to avoid
        // "delve" and "moreover" — words it would never have used — and then
        // checked against nothing at all.
        $this->assertStringContainsString('важно отметить', $style->instructions('ru'));
        $this->assertStringNotContainsString('delve', $style->instructions('ru'));

        $this->assertStringContainsString('delve', $style->instructions('en'));
        $this->assertStringContainsString('além disso', $style->instructions('pt-PT'));
    }

    #[Test]
    public function the_writer_is_not_told_to_ration_punctuation_its_language_requires(): void
    {
        $style = app(HouseStyle::class);

        // Telling a Russian writer to use at most two em dashes would make its
        // prose wrong, not cleaner.
        $this->assertStringNotContainsString('em dashes', $style->instructions('ru'));
        $this->assertStringNotContainsString('No semicolons', $style->instructions('ru'));

        $this->assertStringContainsString('em dashes', $style->instructions('en'));
        $this->assertStringContainsString('No semicolons', $style->instructions('en'));

        // Portuguese keeps the em-dash budget and loses the semicolon rule.
        $this->assertStringContainsString('em dashes', $style->instructions('pt-PT'));
        $this->assertStringNotContainsString('No semicolons', $style->instructions('pt-PT'));
    }

    // ------------------------------------------------------- the limitations

    /** @return iterable<string, array{string, string}> */
    public static function limitations(): iterable
    {
        yield 'english' => ['en', '## Where this is the wrong call'];
        yield 'portuguese' => ['pt-PT', '## Quando não vale a pena contratar'];
        yield 'russian' => ['ru', '## Когда уборка не подходит'];
        yield 'ukrainian' => ['uk', '## Коли це не підходить'];
    }

    #[Test]
    #[DataProvider('limitations')]
    public function the_limitations_section_is_found_in_each_language(string $locale, string $heading): void
    {
        $body = "# Заголовок\n\nProse.\n\n{$heading}\n\nMore prose.";

        $this->assertTrue(app(HouseStyle::class)->hasLimitations($body, $locale));
    }

    #[Test]
    public function a_missing_limitations_section_still_fails_in_a_known_language(): void
    {
        $style = app(HouseStyle::class);

        // The guard must not become a blanket excuse: a language we do list has
        // to be able to fail this check, or the critical rule is dead.
        $this->assertFalse($style->hasLimitations('## Preços e horários', 'pt-PT'));
        $this->assertFalse($style->hasLimitations('## Цены и время', 'ru'));
    }

    #[Test]
    public function an_unlisted_language_reports_unmeasured_rather_than_missing(): void
    {
        // Null, not false. This is a critical check, and returning false for a
        // language whose phrasings nobody listed would make every article in it
        // unpublishable for something it probably did.
        $this->assertNull(app(HouseStyle::class)->hasLimitations('## Bir bölüm', 'tr'));
    }
}
