<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Content;

use App\Support\Content\SafeMarkdown;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SafeMarkdownTest extends TestCase
{
    #[Test]
    public function it_strips_scriptable_html_but_keeps_markdown_structure(): void
    {
        $html = app(SafeMarkdown::class)->render(<<<'MD'
            # Safe heading

            <script>alert('xss')</script>
            <img src=x onerror=alert(1)>

            [unsafe](javascript:alert(1))
            MD);

        $this->assertStringContainsString('<h1>Safe heading</h1>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
