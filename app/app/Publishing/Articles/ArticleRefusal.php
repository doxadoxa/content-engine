<?php

declare(strict_types=1);

namespace App\Publishing\Articles;

use Illuminate\Validation\ValidationException;

/**
 * A refusal to approve or publish an article, with a code that says which.
 *
 * Still a ValidationException, so every caller that shows the sentence to the
 * owner keeps working. The code is what a schedule stores as `blocked_code`,
 * so a screen can tell a failed fact check from a spent allowance without
 * reading the sentence.
 */
final class ArticleRefusal extends ValidationException
{
    public string $blockedCode = BlockedCode::OTHER;

    public static function because(string $key, string $message, string $code): self
    {
        $refusal = self::withMessages([$key => $message]);
        $refusal->blockedCode = $code;

        return $refusal;
    }
}
