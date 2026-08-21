<?php

declare(strict_types=1);

namespace App\Support\Corpus;

use App\Ai\Contracts\ModelSession;
use App\Ai\ModelRequest;
use App\Enums\SitePageKind;
use Illuminate\Support\Str;

/**
 * Which pages on a site are the business stating its own offer.
 *
 * The question {@see SiteLibrary} used to answer from URL path markers — is
 * `/journal/` in the path — and could only answer two ways. Path markers are a
 * guess about somebody else's information architecture, and the guess is only
 * ever wrong in one direction that matters: a `/services` page looks like
 * neither an article nor anything else, so it was never fetched, so the planner
 * never learned what the business sells.
 *
 * **Batched, because the unit of cost is the call and not the page.** Sixty
 * pages at one call each is sixty calls a week per project to answer a question
 * whose input is a URL, a title and a paragraph. Twenty-five at a time makes it
 * three, and the answers do not interact — a page's kind does not depend on
 * which other pages were in the batch, so the batching is an accounting detail
 * rather than a change to the question.
 *
 * **Anything unreadable is {@see SitePageKind::Other}.** The cost is asymmetric:
 * a commercial page missed is a fact the planner does not get and can survive
 * without, while a contact form admitted as commercial is a phone number the
 * planner may present as evidence. Nothing re-reads a page automatically, so a
 * wrong answer is corrected by a person rather than by another crawl — which is
 * the other reason to fail toward the harmless value.
 */
class PageClassifier
{
    /** Pages per call. Twenty-five titles and excerpts is a comfortable prompt. */
    private const int BATCH = 25;

    /** Of each page, to decide what it is. The first paragraph always says. */
    private const int EXCERPT_CHARS = 600;

    /**
     * Classify pages, keyed by whatever key the caller passed in.
     *
     * Takes and returns plain arrays rather than models so that the caller
     * decides what to persist — this class does not know that an editorial
     * page's body is thrown away, and should not.
     *
     * @param  array<string, array{url: string, title: string, text: string}>  $pages
     * @return array<string, SitePageKind>
     */
    public function classify(array $pages, ModelSession $models): array
    {
        $kinds = [];

        foreach (array_chunk($pages, self::BATCH, true) as $batch) {
            foreach ($this->classifyBatch($batch, $models) as $key => $kind) {
                $kinds[$key] = $kind;
            }
        }

        return $kinds;
    }

    /**
     * @param  array<string, array{url: string, title: string, text: string}>  $batch
     * @return array<string, SitePageKind>
     */
    private function classifyBatch(array $batch, ModelSession $models): array
    {
        $numbered = [];
        $keys = array_keys($batch);

        foreach (array_values($batch) as $index => $page) {
            $numbered[] = implode("\n", [
                'PAGE '.($index + 1),
                'url: '.$page['url'],
                'title: '.$page['title'],
                'text: '.Str::limit(Str::squish($page['text']), self::EXCERPT_CHARS),
            ]);
        }

        $answer = $models->send(new ModelRequest(
            role: 'utility',
            instructions: implode("\n\n", [
                'You are sorting the pages of one business\'s website by what each page is for. You are '
                    .'not judging quality and not summarising — one label per page, nothing else.',
                implode("\n", [
                    'The labels:',
                    '- commercial: the business stating its own offer. What it sells, prices, packages, '
                        .'add-ons, what is included, how long it takes, areas covered, guarantees, who '
                        .'the team is, frequently asked questions about the service.',
                    '- editorial: something written about a subject. Articles, journal entries, guides, '
                        .'news, tips. A page that teaches rather than sells is editorial even when it '
                        .'mentions the service.',
                    '- other: everything that is neither. Contact forms, legal and privacy pages, '
                        .'language switchers, login, empty index or listing pages that only link '
                        .'elsewhere, error pages.',
                ]),
                'When a page could be two of these, choose by what most of the text is doing. A services '
                    .'page with a paragraph of advice on it is still commercial; a guide that ends with '
                    .'a booking link is still editorial.',
            ]),
            prompt: implode("\n\n", [
                implode("\n\n", $numbered),
                'Reply with JSON and nothing else: {"pages":[{"page":1,"kind":"'
                    .SitePageKind::alternation().'"}]}. One entry per page, in order.',
            ]),
        ));

        return $this->decode($answer->text, $keys);
    }

    /**
     * The model's answer, mapped back onto the caller's keys.
     *
     * Positional, because the prompt numbers the pages and asks for them in
     * order. A missing or unreadable entry falls back rather than shifting
     * every page after it onto the wrong label — which is the failure mode of
     * consuming a list positionally and the reason each entry carries its own
     * number.
     *
     * @param  list<string>  $keys
     * @return array<string, SitePageKind>
     */
    private function decode(string $text, array $keys): array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        $decoded = $start === false || $end === false || $end <= $start
            ? null
            : json_decode(substr($text, $start, $end - $start + 1), true);

        $answers = is_array($decoded) && is_array($decoded['pages'] ?? null) ? $decoded['pages'] : [];
        $byPosition = [];

        foreach ($answers as $answer) {
            if (! is_array($answer)) {
                continue;
            }

            $position = (int) ($answer['page'] ?? 0);

            if ($position >= 1 && $position <= count($keys)) {
                $byPosition[$position] = SitePageKind::tryFromLoose($answer['kind'] ?? null)
                    ?? SitePageKind::fallback();
            }
        }

        $kinds = [];

        foreach ($keys as $index => $key) {
            $kinds[$key] = $byPosition[$index + 1] ?? SitePageKind::fallback();
        }

        return $kinds;
    }
}
