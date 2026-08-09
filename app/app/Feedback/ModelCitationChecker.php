<?php

declare(strict_types=1);

namespace App\Feedback;

use App\Ai\Contracts\ModelGateway;
use App\Ai\ModelRequest;
use App\Feedback\Contracts\CitationChecker;

/**
 * Asks assistants the target query and looks for the brand in the answer (§9.3).
 *
 * Through the model gateway, so it is metered like everything else — checking
 * citability across a corpus is itself a running cost, and §6 is clear that a
 * cost the engine cannot see is a cost nobody can decide about.
 *
 * A crude signal by construction: an answer that mentions the brand is not
 * proof of a citation, and one that does not is not proof of absence. It is a
 * trend line, which is what §9.3 asks for.
 */
class ModelCitationChecker implements CitationChecker
{
    public function __construct(private readonly ModelGateway $models) {}

    public function name(): string
    {
        return 'model';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    /** @return array<string, bool> */
    public function check(string $query, string $brand): array
    {
        $answer = $this->models->send(new ModelRequest(
            role: 'utility',
            instructions: 'You answer as a general-purpose assistant would, from what you know.',
            prompt: $query,
        ));

        return [
            $answer->model => str_contains(
                mb_strtolower($answer->text),
                mb_strtolower($brand),
            ),
        ];
    }
}
