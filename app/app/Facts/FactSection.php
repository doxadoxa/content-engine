<?php

declare(strict_types=1);

namespace App\Facts;

/** Immutable text supplied by a recorded answer or page capture, never a live mutable source. */
final readonly class FactSection
{
    /**
     * @param  array<string, mixed>  $context
     * @param  list<array{id: string, url: string, title: string}>  $references
     */
    public function __construct(public string $key, public string $text, public array $context = [], public array $references = []) {}
}
