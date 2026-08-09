<?php

declare(strict_types=1);

namespace App\Visibility;

use App\Visibility\Contracts\LlmVisibilityGateway;
use RuntimeException;

/**
 * The assistant panel the suite runs against.
 *
 * Deterministic, and scriptable per platform, because the questions the tests
 * ask are "does a brand named in three of four answers score 75%" and "does a
 * locale nobody generated prompts for score anything at all" — neither of which
 * survives a fixture that answers differently each run.
 */
class FakeLlmVisibility implements LlmVisibilityGateway
{
    /** @var array<string, LlmAnswer|null> */
    private array $scripted = [];

    /** @var list<array{platform: string, prompt: string, country: string|null}> */
    private array $asked = [];

    /** @var list<string> */
    private array $platforms = ['chat_gpt', 'gemini', 'claude', 'perplexity'];

    private bool $configured = true;

    private bool $failing = false;

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function unconfigured(): self
    {
        $this->configured = false;

        return $this;
    }

    /** @param  list<string>  $platforms */
    public function withPlatforms(array $platforms): self
    {
        $this->platforms = $platforms;

        return $this;
    }

    /** @return list<string> */
    public function platforms(): array
    {
        return $this->platforms;
    }

    /**
     * Script one answer. Null means the assistant declined.
     *
     * @param  list<array{url: string, title: string}>  $citations
     */
    public function willAnswer(string $platform, string $prompt, ?string $text, array $citations = []): self
    {
        $this->scripted[$platform.'|'.$prompt] = $text === null
            ? null
            : new LlmAnswer($platform, 'fake-model', $text, $citations, 0.001);

        return $this;
    }

    /** Every assistant throws — the "it is not four outages, it is us" case. */
    public function failEverything(): self
    {
        $this->failing = true;

        return $this;
    }

    public function ask(string $platform, string $prompt, ?string $countryIso = null): ?LlmAnswer
    {
        $this->asked[] = ['platform' => $platform, 'prompt' => $prompt, 'country' => $countryIso];

        if ($this->failing) {
            throw new RuntimeException("The {$platform} assistant is unreachable.");
        }

        $key = $platform.'|'.$prompt;

        if (array_key_exists($key, $this->scripted)) {
            return $this->scripted[$key];
        }

        // Unscripted: an answer that names nobody. A default that mentioned the
        // brand would make every test that forgot to script one pass for the
        // wrong reason.
        return new LlmAnswer(
            platform: $platform,
            model: 'fake-model',
            text: "Several providers cover this. Compare a few before deciding.\n\nPrompt asked: {$prompt}",
            citations: [['url' => 'https://directory.test/list', 'title' => 'A directory']],
            moneySpent: 0.001,
        );
    }

    /** @return list<array{platform: string, prompt: string, country: string|null}> */
    public function asked(): array
    {
        return $this->asked;
    }
}
