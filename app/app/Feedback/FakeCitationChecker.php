<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Feedback\Contracts\CitationChecker;

/** Citability for the suite: scripted per query. */
class FakeCitationChecker implements CitationChecker
{
    /** @var array<string, array<string, bool>> */
    private array $scripted = [];

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * @param  array<string, bool>  $assistants
     */
    public function willFind(string $query, array $assistants): self
    {
        $this->scripted[$query] = $assistants;

        return $this;
    }

    /** @return array<string, bool> */
    public function check(string $query, string $brand): array
    {
        return $this->scripted[$query] ?? ['chatgpt' => false, 'perplexity' => false];
    }
}
