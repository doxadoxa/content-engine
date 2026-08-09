<?php

declare(strict_types=1);

namespace App\Content;

use Illuminate\Support\Str;

/**
 * The house style, from `product/humanizated-articles.md`.
 *
 * Two halves that must agree: the instructions handed to the writing step, and
 * the checks run over what comes back. Keeping them in one class is the point —
 * a rule that is asked for and never checked is a suggestion, and a rule that
 * is checked and never asked for is a trap.
 *
 * The document is the source of truth. Section numbers below point at it so the
 * two can be reconciled when either changes.
 */
class HouseStyle
{
    /**
     * §4.1 — near-diagnostic of machine-written prose, per language.
     *
     * Kept deliberately small and high-precision rather than broad. Every entry
     * has to be a phrase a careful human writer would rarely reach for in
     * commercial prose, because a false positive here fails a good article on a
     * critical check — and in a domain like cleaning, half the obvious
     * candidates ("efficient", "quality", "reliable") are the subject matter.
     *
     * The bar is the English list's own: `moreover` and `furthermore` are on it
     * because models overuse them distinctively, not because they are formal.
     * "por fim" was on the Portuguese list and failed a good article on a
     * critical check for saying "lastly" once — it is ordinary Portuguese with
     * no counterpart in the English set, so it came back off.
     *
     * These are not translations of the English list. A model writing Russian
     * has its own habits — "важно отметить", "в современном мире" — and
     * translating "delve" would produce a word nobody overuses.
     *
     * @var array<string, list<string>>
     */
    public const array BANNED = [
        'en' => [
            'delve', 'moreover', 'furthermore', 'additionally', 'notably', 'crucially',
            "it's worth noting", 'it is worth noting', "in today's fast-paced",
            'in the ever-evolving', 'ever-evolving landscape', 'robust', 'seamless',
            'seamlessly', 'leverage', 'leveraging', 'utilize', 'utilise', 'unlock',
            'empower', 'revolutionize', 'revolutionise', 'game-changer', 'testament to',
            'at the end of the day', 'that said', 'when it comes to', 'dive into',
            'tapestry', 'realm', 'embark', 'foster', 'pivotal', 'myriad', 'plethora',
        ],
        'pt' => [
            'além disso', 'ademais', 'vale ressaltar', 'é importante ressaltar',
            'vale a pena mencionar', 'importante destacar', 'no mundo atual',
            'nos dias de hoje', 'no cenário atual', 'em constante evolução',
            'robusto', 'otimizar', 'desvendar', 'revolucionar', 'mergulhar',
            'em suma', 'de suma importância', 'uma vasta gama',
            'leque de opções', 'papel fundamental', 'peça-chave',
        ],
        'ru' => [
            'важно отметить', 'стоит отметить', 'следует отметить',
            'необходимо отметить', 'в современном мире', 'в наши дни',
            'в эпоху', 'играет ключевую роль', 'играет важную роль',
            'неотъемлемой частью', 'широкий спектр', 'целый ряд',
            'давайте разберёмся', 'давайте разберемся', 'погрузимся',
            'в заключение', 'подводя итог', 'залог успеха',
            'не за горами', 'на сегодняшний день',
        ],
        'uk' => [
            'важливо зазначити', 'варто зазначити', 'варто відзначити',
            'слід зазначити', 'у сучасному світі', 'в сучасному світі',
            'у наші дні', 'відіграє ключову роль', 'відіграє важливу роль',
            "невід'ємною частиною", 'широкий спектр', 'ціла низка',
            'davайте розберемося', 'зануримося', 'на завершення',
            'підбиваючи підсумок', 'запорука успіху', 'на сьогоднішній день',
        ],
    ];

    /**
     * §4.2 — the most recognisable AI sentence shapes there are, per language.
     *
     * The risk here is higher than with the word lists, because the shape is
     * often a legitimate construction that models simply reach for too often.
     * "Не только X, но и Y" is ordinary Russian; so the patterns are anchored
     * tightly — a short span between the halves — so an incidental use of the
     * words does not match while the formula does.
     *
     * @var array<string, list<string>>
     */
    private const array SYMMETRIES = [
        'en' => [
            '/\bnot just [^,.]{1,40}, but\b/iu',
            '/\bit(?:\'s| is) not about [^,.]{1,40}, it(?:\'s| is) about\b/iu',
            '/\bisn(?:\'t| not) a nice[- ]to[- ]have\b/iu',
            '/\bwhether you(?:\'re| are) a [^,.]{1,40} or a\b/iu',
        ],
        'pt' => [
            '/\bnão (?:apenas|só) [^,.]{1,40}, mas (?:também|sim)\b/iu',
            '/\bnão se trata de [^,.]{1,40}, (?:mas|e) sim\b/iu',
            '/\bseja você [^,.]{1,40} ou\b/iu',
        ],
        'ru' => [
            '/\bне только [^,.]{1,40}, но и\b/iu',
            '/\bэто не просто [^,.]{1,40}, это\b/iu',
            '/\bне столько [^,.]{1,40}, сколько\b/iu',
        ],
        'uk' => [
            '/\bне (?:лише|тільки) [^,.]{1,40}, але й\b/iu',
            '/\bце не просто [^,.]{1,40}, це\b/iu',
            '/\bне стільки [^,.]{1,40}, скільки\b/iu',
        ],
    ];

    /**
     * §2.1 — the strongest tell in English, and not a tell at all elsewhere.
     *
     * Listed per language because the em dash is punctuation in one and grammar
     * in another. Russian and Ukrainian require тире where the copula is
     * dropped — «Уборка — это просто» is correct, not a tic — so they are
     * absent here and no budget is applied. Same reasoning for the semicolon:
     * §2.2 argues from *register* in English commercial prose, and Russian
     * expository writing uses them freely and correctly.
     *
     * @var array<string, int>
     */
    private const array MAX_EM_DASHES = ['en' => 2, 'pt' => 2];

    /** Languages whose commercial register treats the semicolon as filler. */
    private const array AVOIDS_SEMICOLONS = ['en'];

    /**
     * What the writer is told, verbatim enough that the checks below can hold
     * it to it.
     */
    public function instructions(?string $locale = null): string
    {
        $language = $this->language($locale);

        return implode("\n", array_values(array_filter([
            'HOUSE STYLE. These are not suggestions; the draft is checked against them.',
            '',
            'Rhythm. Vary sentence length unevenly — three long sentences then a four-word one,',
            'not short-long-short-long, which is its own pattern. Use a fragment now and then.',
            'Not a migration. An addition. Let paragraphs be different lengths; a one-sentence',
            'paragraph is legitimate and lands hard. Do not end every section with a summarising',
            'line, and do not make every list three items long.',
            '',
            // Only where the language has the habit. Telling a Russian writer
            // to ration the em dash would make its prose wrong: тире stands in
            // for the dropped copula there, and the checks know it.
            isset(self::MAX_EM_DASHES[$language])
                ? 'Punctuation. At most '.self::MAX_EM_DASHES[$language].' em dashes in the whole '
                    .'article; prefer a full stop, a comma or a colon.'
                : null,
            in_array($language, self::AVOIDS_SEMICOLONS, true) ? 'No semicolons.' : null,
            'No bold inside body sentences. No scare quotes.',
            '',
            // The same list the draft is checked against, in the language it is
            // being written in. It used to be the English list whatever the
            // article's language, so a Russian draft was told to avoid "delve"
            // and then checked for nothing at all.
            'Never use these words or phrases: '.implode(', ', self::BANNED[$language]).'.',
            '',
            $this->symmetryWarning($language),
            'If the idea is worth stating, state it directly.',
            '',
            'Openings. Start with a concrete situation, never with a definition. Section headings',
            'are declarative and specific, not "Benefits" or "Conclusion". Sections should be',
            'visibly uneven in length — the one with most to say is longest.',
            '',
            'Honesty. Include a section naming two to four genuine limitations: where this does',
            'not fit, who it is wrong for, what it will not solve. Real ones. This is the single',
            'most important rule here — it is what gets a piece read and cited rather than',
            'skimmed. Where a benefit is table stakes for the whole category, say so and move',
            'past it instead of claiming it as a differentiator.',
            '',
            'Ending. Do not restate the article. End on the last piece of the argument, a',
            'practical takeaway, or an open question.',
        ], static fn (?string $line): bool => $line !== null)));
    }

    /**
     * What the draft got wrong, in the reader's terms.
     *
     * @return list<string>
     */
    public function violations(string $body, ?string $locale = null): ?array
    {
        $language = $this->language($locale);

        // Null when this language has no rule we can honestly apply, so the
        // caller reports "not measured" rather than a pass nothing earned.
        if (! isset(self::BANNED[$language])) {
            return null;
        }

        $found = [];

        // Typography, where the language has an opinion about it. In Russian
        // and Ukrainian the em dash is grammar rather than style, so no budget
        // exists and none is applied.
        $budget = self::MAX_EM_DASHES[$language] ?? null;

        if ($budget !== null) {
            $emDashes = mb_substr_count($body, '—');

            if ($emDashes > $budget) {
                $found[] = "{$emDashes} em dashes";
            }
        }

        if (in_array($language, self::AVOIDS_SEMICOLONS, true) && mb_substr_count($body, ';') > 1) {
            $found[] = 'semicolons';
        }

        $banned = $this->bannedWordsIn($body, $language);

        if ($banned !== []) {
            $found[] = implode(', ', array_slice($banned, 0, 4))
                .(count($banned) > 4 ? ' and '.(count($banned) - 4).' more' : '');
        }

        foreach (self::SYMMETRIES[$language] as $pattern) {
            if (preg_match($pattern, $body) === 1) {
                $found[] = 'a "not just X, but Y" construction';

                break;
            }
        }

        return $found;
    }

    /**
     * Whether the banned-word and symmetry lists apply to a language.
     *
     * Every list is written for its own language rather than translated from
     * the English one — a model writing Russian has its own habits, and
     * translating "delve" would produce a word nobody overuses.
     */
    public function checksVocabulary(?string $locale): bool
    {
        return isset(self::BANNED[$this->language($locale)]);
    }

    /**
     * §6.1 — the highest-leverage rule in the guide, so it is checked on its
     * own rather than folded into the style score.
     */
    public function hasLimitations(string $body, ?string $locale = null): ?bool
    {
        $markers = $this->limitationMarkers($locale);

        // Null rather than false. This is the guide's highest-leverage rule and
        // a critical check: reporting "no limitations section" for a language
        // whose phrasings we never listed would fail every article in it for
        // something it probably did.
        if ($markers === []) {
            return null;
        }

        $haystack = mb_strtolower($body);

        foreach ($markers as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §3.1 — uniform sentence length is the tell that everything else here is
     * a way of breaking.
     *
     * Measured as the spread of sentence lengths: prose where every sentence is
     * about the same length has a low one whatever its average.
     */
    public function rhythmIsVaried(string $body): bool
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', strip_tags($body)) ?: [];

        $lengths = [];

        foreach ($sentences as $sentence) {
            $words = Words::count($sentence);

            if ($words > 0) {
                $lengths[] = $words;
            }
        }

        if (count($lengths) < 8) {
            return false;
        }

        $mean = array_sum($lengths) / count($lengths);

        if ($mean <= 0.0) {
            return false;
        }

        $variance = array_sum(array_map(
            static fn (int $length): float => ($length - $mean) ** 2,
            $lengths,
        )) / count($lengths);

        // Coefficient of variation. Human editorial prose sits well above 0.45;
        // a model left to itself lands nearer 0.25.
        return (sqrt($variance) / $mean) >= 0.45;
    }

    /**
     * The symmetrical shapes to avoid, named in the article's own language.
     *
     * Worth spelling out rather than describing abstractly: "avoid symmetrical
     * constructions" is advice a model nods at, where the actual formula in its
     * own language is something it can recognise itself doing.
     */
    private function symmetryWarning(string $language): string
    {
        return match ($language) {
            'pt' => 'Nunca use estas formas: "não apenas X, mas também Y"; "não se trata de X, e sim '
                .'de Y"; "seja você X ou Y".',
            'ru' => 'Никогда не используйте эти конструкции: «не только X, но и Y»; «это не просто X, '
                .'это Y»; «не столько X, сколько Y».',
            'uk' => 'Ніколи не використовуйте ці конструкції: «не лише X, але й Y»; «це не просто X, '
                .'це Y»; «не стільки X, скільки Y».',
            default => 'Never use these shapes: "not just X, but Y"; "it is not about X, it is about '
                .'Y"; "X is not a nice-to-have, it is a necessity"; "whether you are a X or a Y".',
        };
    }

    /** The two-letter language of a locale tag, defaulting to English. */
    private function language(?string $locale): string
    {
        $language = mb_strtolower(mb_substr((string) ($locale ?? 'en'), 0, 2));

        return $language === '' ? 'en' : $language;
    }

    /**
     * The phrases that mean "here is where this does not fit", per language.
     *
     * Deliberately broad, and deliberately not a translation of one list. The
     * heading is written by a model told to make it declarative, so it says
     * "Where a cleaning service is the wrong call" rather than "Limitations" —
     * and a narrow list marks a perfectly good section missing.
     *
     * Only the languages this codebase has actually been asked for. An unlisted
     * language returns nothing, and the check reports itself unmeasured rather
     * than failed.
     *
     * @return list<string>
     */
    private function limitationMarkers(?string $locale): array
    {
        return match (mb_strtolower(mb_substr((string) ($locale ?? 'en'), 0, 2))) {
            'en' => [
                'not for', "doesn't fit", 'does not fit', 'where this fails',
                'limitation', 'not the right', 'wrong call', 'wrong choice',
                'wrong fit', 'will not solve', "won't solve", 'cannot solve',
                'not suitable', 'when to look elsewhere', 'look elsewhere',
                'what this cannot', 'what this can’t', "what this can't",
                'not worth', 'skip this', 'when not to', 'when it is not',
                "when it isn't", 'do not need', "don't need", 'no point',
            ],
            'pt' => [
                'não serve', 'nao serve', 'não é indicado', 'nao e indicado',
                'não vale a pena', 'nao vale a pena', 'limitaç', 'limitac',
                'quando não', 'quando nao', 'não resolve', 'nao resolve',
                'não é a melhor', 'nao e a melhor', 'não precisa', 'nao precisa',
                'não faz sentido', 'nao faz sentido', 'onde falha',
                'não é para', 'nao e para', 'procure outra', 'outra solução',
            ],
            'ru' => [
                'не подходит', 'не стоит', 'не поможет', 'не решит',
                'ограничен', 'когда не', 'не нужно', 'не для',
                'не имеет смысла', 'лучше выбрать', 'не тот случай',
                'не справится', 'исключения', 'что не входит',
            ],
            'uk' => [
                'не підходить', 'не варто', 'не допоможе', 'не вирішить',
                'обмеж', 'коли не', 'не потрібно', 'не для',
                'не має сенсу', 'краще обрати', 'не той випадок',
                'не впорається', 'винятки', 'що не входить',
            ],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function bannedWordsIn(string $body, string $language): array
    {
        // Punctuation flattened to spaces before matching. The padding below is
        // what stops "realm" matching "realms", but it also stopped it matching
        // "Moreover," — and a banned word is no less a tell for being followed
        // by a comma. Only the marks that end a word are flattened; apostrophes
        // and hyphens stay, because "it's worth noting" is one of the phrases.
        $flattened = (string) preg_replace('/[^\p{L}\p{N}\'’\-]+/u', ' ', mb_strtolower(strip_tags($body)));

        $haystack = ' '.Str::squish($flattened).' ';
        $found = [];

        foreach (self::BANNED[$language] ?? [] as $word) {
            // Padded both sides, so "realm" does not match "realms" of nothing
            // and "foster" does not match a surname.
            if (str_contains($haystack, ' '.$word.' ')) {
                $found[] = $word;
            }
        }

        return $found;
    }
}
