<?php

declare(strict_types=1);

namespace App\Support\Content;

use Illuminate\Support\Str;

/** Render model-influenced Markdown without preserving executable HTML. */
final class SafeMarkdown
{
    public function render(string $markdown): string
    {
        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
