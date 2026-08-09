<?php

declare(strict_types=1);

namespace App\Content;

use App\Support\Content\Squish;
use Illuminate\Support\Str;

/**
 * How hard the prose is to read.
 *
 * Flesch-Kincaid and its relatives are **English formulas**. Their constants
 * were fitted to English syllable counts and English sentence structure, and
 * applied to Portuguese they produce a confident number that means nothing —
 * Portuguese averages more syllables per word, so the same quality of writing
 * scores several grades harder.
 *
 * So each language gets the adaptation published for it, or nothing at all:
 *
 *   en  Flesch-Kincaid grade level — a US school grade, where lower is easier.
 *   pt  Martins et al. (1996), the Brazilian Portuguese adaptation of Flesch
 *       Reading Ease. A 0–100 scale where *higher* is easier.
 *   ru  Oborneva (2006), the Russian adaptation of Flesch Reading Ease, refitted
 *       against Russian syllable and word length. Also 0–100, higher is easier.
 *
 * The two scales run in opposite directions, which is why nothing here compares
 * them to each other and why the caller is told which one it got.
 *
 * Ukrainian is deliberately absent. It is phonotactically close enough to
 * Russian that Oborneva's constants would produce a plausible number, and
 * plausible is exactly the failure mode this class exists to avoid: there is no
 * published adaptation, so there is no honest answer.
 */
class Readability
{
    /**
     * Languages this can honestly score, and the scale each answers on.
     *
     * @var array<string, string>
     */
    private const array SCALES = ['en' => 'grade', 'pt' => 'ease', 'ru' => 'ease'];

    /**
     * A ceiling, and deliberately no floor.
     *
     * The worry is prose nobody can follow, not prose anybody can. There is no
     * such thing as too readable for a consumer article, and a floor would
     * fight our own style guide — "use fragments", "a one-sentence paragraph
     * lands hard" — which drives the grade down on purpose. Writing that
     * scores grade three because it is direct is doing what it was asked.
     */
    private const float HARDEST_GRADE = 11.0;

    /**
     * The same worry on the other scale, per language.
     *
     * Reading Ease runs the other way — higher is easier — so the guard is a
     * floor rather than a ceiling, and there is still no bound at the easy end
     * for the same reason.
     *
     * Measured, not taken from the English band table, which is where the first
     * number came from and why it was wrong. Six published articles from a real
     * site in this domain, in both languages (they are translations of each
     * other, which is what makes the comparison worth anything):
     *
     *   pt   28.0  35.1  35.5  41.9  44.2  47.8      median 41.9
     *   ru   24.4  25.7  29.2  30.7  34.3  37.7      median 30.7
     *
     * A floor of 50 would have failed all twelve. And the two languages sit
     * about eleven points apart on identical content, so one shared number was
     * never going to serve both — that gap is the formulas' constants, not the
     * writing.
     *
     * Set near the hard end of what a real site actually ships: low enough that
     * ordinary prose passes, high enough that the check is not dead. A small
     * sample from one site, and worth re-measuring against a wider one.
     */
    private const array HARDEST_EASE = ['pt' => 32.0, 'ru' => 26.0];

    public function supports(?string $locale): bool
    {
        if ($locale === null || $locale === '') {
            return false;
        }

        return isset(self::SCALES[$this->language($locale)]);
    }

    /**
     * @return array{scale: string, language: string, grade: float, sentences: int, words: int, average_sentence: float, passive_ratio: float|null}|null
     */
    public function measure(string $text, ?string $locale): ?array
    {
        if (! $this->supports($locale)) {
            return null;
        }

        $language = $this->language($locale);

        $plain = $this->plain($text);
        $sentences = $this->sentences($plain);
        $words = $this->words($plain);

        if ($sentences === [] || $words === []) {
            return null;
        }

        $perSentence = count($words) / count($sentences);
        $perWord = array_sum(array_map(
            fn (string $word): int => $this->syllables($word, $language),
            $words,
        )) / count($words);

        $value = match ($language) {
            // Martins et al. (1996), Brazilian Portuguese.
            'pt' => 248.835 - (1.015 * $perSentence) - (84.6 * $perWord),
            // Oborneva (2006), Russian.
            'ru' => 206.835 - (1.3 * $perSentence) - (60.1 * $perWord),
            // Flesch-Kincaid grade level.
            default => (0.39 * $perSentence) + (11.8 * $perWord) - 15.59,
        };

        return [
            'scale' => self::SCALES[$language],
            // Carried so the comfort band can be the one fitted to this
            // language: the same content scores eleven points apart in
            // Portuguese and Russian, and that gap is the formulas' constants.
            'language' => $language,
            // Still called `grade` because that is what it is on the English
            // scale and every caller reads it; `scale` says what it means.
            'grade' => round($value, 1),
            'sentences' => count($sentences),
            'words' => count($words),
            'average_sentence' => round($perSentence, 1),
            // Null outside English. The test is "a form of to be plus a past
            // participle", which is a fact about English grammar — Russian
            // builds the passive with -ся and short participles, Portuguese
            // with ser plus agreement. Reporting 0% would read as a clean pass
            // rather than as an unasked question.
            'passive_ratio' => $language === 'en' ? $this->passiveRatio($sentences) : null,
        ];
    }

    /**
     * The measurement in the words of the scale it came from.
     *
     * @param  array{scale: string, language: string, grade: float, sentences: int, words: int, average_sentence: float, passive_ratio: float|null}  $measured
     */
    public function describe(array $measured): string
    {
        return $measured['scale'] === 'ease'
            ? sprintf('reading ease %.0f of 100, sentences average %.1f words', $measured['grade'], $measured['average_sentence'])
            : sprintf('grade %.1f, sentences average %.1f words', $measured['grade'], $measured['average_sentence']);
    }

    /**
     * Whether a general audience can follow it.
     *
     * @param  array{scale: string, language: string, grade: float, sentences: int, words: int, average_sentence: float, passive_ratio: float|null}  $measured
     */
    public function isComfortable(array $measured): bool
    {
        // The scales run in opposite directions, so the comparison flips with
        // them. Getting this backwards would pass exactly the prose it is
        // meant to catch.
        return $measured['scale'] === 'ease'
            ? $measured['grade'] >= (self::HARDEST_EASE[$measured['language']] ?? 0.0)
            : $measured['grade'] <= self::HARDEST_GRADE;
    }

    /** The two-letter language of a locale tag. */
    private function language(?string $locale): string
    {
        return mb_strtolower(Str::before((string) $locale, '-'));
    }

    /**
     * Roughly what share of sentences are passive.
     *
     * A crude test — a form of "to be" followed by a past participle — and
     * crude is the right amount of effort: this is a nudge about voice, not a
     * grammar checker, and the writer is a model that responds to being told
     * a third of its sentences are passive.
     *
     * @param  list<string>  $sentences
     */
    private function passiveRatio(array $sentences): float
    {
        $passive = 0;

        foreach ($sentences as $sentence) {
            if (preg_match('/\b(is|are|was|were|be|been|being)\s+(\w+ed|done|made|given|taken|seen|known|used|found)\b/i', $sentence) === 1) {
                $passive++;
            }
        }

        return round($passive / max(1, count($sentences)), 3);
    }

    /**
     * English syllable counting, by vowel groups.
     *
     * Approximate on purpose. Exact counting needs a pronunciation dictionary,
     * and Flesch-Kincaid was fitted against counts no more careful than this.
     */
    private function syllables(string $word, string $language = 'en'): int
    {
        if ($language !== 'en') {
            return $this->vowelGroups($word, $language);
        }

        $word = preg_replace('/[^a-z]/', '', mb_strtolower($word)) ?? '';

        if ($word === '') {
            return 0;
        }

        // A trailing silent `e` is not a syllable — "make" is one, not two.
        $word = preg_replace('/e$/', '', $word) ?? $word;

        $groups = preg_match_all('/[aeiouy]+/', $word);

        return max(1, (int) $groups);
    }

    /**
     * Syllables outside English, by vowels.
     *
     * In Russian this is not an approximation: a syllable is a vowel, so the
     * count is exact — more reliable than anything the English branch above
     * manages. Portuguese counts vowel *groups*, which merges diphthongs and
     * slightly undercounts hiatus; Martins' constants were fitted against
     * counting no more careful than this.
     */
    private function vowelGroups(string $word, string $language): int
    {
        $vowels = $language === 'ru'
            ? 'аеёиоуыэюя'
            : 'aeiouáéíóúâêîôûãõàèìòùäëïöü';

        $groups = preg_match_all('/['.$vowels.']+/iu', mb_strtolower($word));

        return max(1, (int) $groups);
    }

    /** @return list<string> */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?])\s+/u', $text) ?: [];

        return array_values(array_filter(
            array_map(trim(...), $parts),
            static fn (string $sentence): bool => Words::count($sentence) > 1,
        ));
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        /** @var list<string> $words */
        $words = Words::all($text);

        return $words;
    }

    /** Markdown furniture is not prose: headings, links, code, image alt text. */
    private function plain(string $markdown): string
    {
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/^#{1,6}\s+.*$/m', ' ', $text) ?? $text;
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/[*_`>|-]+/', ' ', $text) ?? $text;

        return Squish::text($text);
    }
}
