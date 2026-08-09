<?php

declare(strict_types=1);

namespace App\Visibility\Contracts;

use App\Feedback\Contracts\CitationChecker;
use App\Visibility\LlmAnswer;

/**
 * Somewhere to actually ask the assistants a question (§9.3, extended).
 *
 * The distinction from {@see CitationChecker}, which
 * already exists and is not being replaced: that one asks *our* model whether a
 * brand tends to get cited for a query. A model with no web access answers that
 * from memory, which is a recollection about the internet rather than a reading
 * of it. This asks ChatGPT, Gemini, Claude and Perplexity the question itself
 * and reads what comes back.
 *
 * The grain differs too. The citation check is per article; this is per project:
 * "for the handful of prompts a buyer would actually type, does the brand appear
 * at all, and who gets cited instead?"
 */
interface LlmVisibilityGateway
{
    /** A short name, recorded on what it produces. */
    public function name(): string;

    /** Whether it is configured well enough to be called. */
    public function isConfigured(): bool;

    /**
     * The assistants it can put a question to.
     *
     * A list rather than a constant because they are bought separately and go
     * down separately. A project should get the answers from the three that
     * replied rather than nothing because the fourth was unavailable.
     *
     * @return list<string>
     */
    public function platforms(): array;

    /**
     * Ask one assistant one question.
     *
     * Null when the assistant declined or returned nothing usable — which is a
     * different fact from an outage, and the caller records it as "asked, no
     * answer" rather than failing a run over it. Real failures throw, so the
     * pipeline's retry logic still has something to sort.
     *
     * `$countryIso` steers the model's own web search, so "best cleaning
     * service" is answered about the country the project sells in.
     */
    public function ask(string $platform, string $prompt, ?string $countryIso = null): ?LlmAnswer;
}
