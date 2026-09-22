<?php

declare(strict_types=1);

namespace App\Opportunities;

use Illuminate\Support\Str;

/** Conservative lexical evidence, always presented as a review signal. */
final class OpportunityText
{
    /** @return list<string> */
    public function tokens(string $text): array
    {
        $stop = ['the', 'and', 'for', 'with', 'your', 'our', 'this', 'that', 'from', 'how', 'what', 'when', 'where', 'which', 'does', 'much', 'para', 'com', 'uma', 'que', 'como', 'qual', 'quanto', 'dos', 'das', 'por', 'can', 'are', 'you', 'near', 'best'];
        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower(Str::ascii(strip_tags($text)))) ?: [];

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 3 && ! in_array($word, $stop, true))));
    }

    public function related(string $query, string $subject): bool
    {
        $tokens = $this->tokens($query);
        $matches = count(array_intersect($tokens, $this->tokens($subject)));

        return count($tokens) > 0 && $matches >= min(2, count($tokens)) && $matches / count($tokens) >= 0.4;
    }

    public function overlap(string $left, string $right): float
    {
        $a = $this->tokens($left);
        $b = $this->tokens($right);
        $union = count(array_unique([...$a, ...$b]));

        return $union > 0 ? count(array_intersect($a, $b)) / $union : 0;
    }

    /** A missing lexical cue is a question to confirm, not a fact about the business. */
    public function missingBuyerAnswer(string $body, string $locale): ?string
    {
        $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);
        if (! in_array($language, ['en', 'pt'], true)) {
            return null;
        }
        $text = Str::lower(Str::ascii($body));
        if (! preg_match('/[€£$]|\b(price|pricing|cost|quote|estimate|eur|usd|gbp|preco|custo|orcamento|estimativa)\b/u', $text)) {
            return 'pricing';
        }
        if (! preg_match('/\b(include[ds]?|including|cover[sed]*|checklist|scope|exclud[eis][a-z]*|inclui[a-z]*|inclu[a-z]*|abrange|exclui[a-z]*|check-list)\b/u', $text)) {
            return 'scope';
        }

        return null;
    }

    public function question(string $gap, string $title): string
    {
        return $gap === 'pricing'
            ? 'For “'.$title.'”, what is the actual price, range, or process for obtaining a quote, including any important conditions?'
            : 'For “'.$title.'”, what is included and excluded, and which options cost extra?';
    }

    public function queryIntent(string $query): ?string
    {
        $query = Str::lower(Str::ascii($query));
        if (preg_match('/\b(price|pricing|cost|quote|preco|custo|orcamento)\b|how much|quanto custa/u', $query)) {
            return 'pricing';
        }
        if (preg_match('/\b(include[ds]?|including|checklist|inclui[a-z]*|inclu[a-z]*)\b|what is covered/u', $query)) {
            return 'scope';
        }

        return null;
    }
}
