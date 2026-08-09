<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Which assistants to ask
    |--------------------------------------------------------------------------
    |
    | DataForSEO fronts each one separately, and each takes a model name.
    |
    | Pick the model a *customer* gets, not a cheap one. This measured
    | `gpt-4.1-mini` on the reasoning that it "measures what an assistant says
    | about a brand, not how clever the assistant is" — which is backwards. The
    | question is what somebody sees when they ask ChatGPT, and nobody is served
    | a mini model from early 2025. Different models cite different sources;
    | that is the entire premise of the thing being measured, so the model is
    | the measurement rather than a line item.
    |
    | `-chat-latest` is what the ChatGPT product itself serves, which is as close
    | as this gets to the real thing. Gemini's Flash and Perplexity's `sonar` are
    | their consumer defaults; Sonnet is Claude's.
    |
    | Measured on one Portuguese prompt: gpt-5.3-chat-latest $0.036/8 citations,
    | gemini-3.6-flash $0.035/32, claude-sonnet-5 $0.073/11, sonar $0.005/20.
    | About $0.15 per prompt across the panel, so a five-prompt three-language
    | sweep is roughly $2.
    |
    | The names are the vendor's own and guessing them costs a whole sweep:
    | `gemini-2.0-flash` and `claude-3-5-haiku-20241022` are perfectly real model
    | names, neither is on DataForSEO's list, and half the panel silently
    | answered nothing for a run while the score looked healthy. Check
    | `/v3/ai_optimization/{platform}/llm_responses/models` before changing one.
    |
    */

    'platforms' => [
        'chat_gpt' => ['model' => env('VISIBILITY_MODEL_CHATGPT', 'gpt-5.3-chat-latest'), 'label' => 'ChatGPT'],
        // Gemini takes no `web_search_country_iso_code`; sending it is a 40501
        // for the whole request rather than an ignored field, so the answer
        // comes back about nowhere in particular instead of about Portugal.
        'gemini' => ['model' => env('VISIBILITY_MODEL_GEMINI', 'gemini-3.6-flash'), 'label' => 'Gemini', 'accepts_country' => false],
        'claude' => ['model' => env('VISIBILITY_MODEL_CLAUDE', 'claude-sonnet-5'), 'label' => 'Claude'],
        'perplexity' => ['model' => env('VISIBILITY_MODEL_PERPLEXITY', 'sonar'), 'label' => 'Perplexity'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompts
    |--------------------------------------------------------------------------
    |
    | How many prompts to keep per locale, and how long before they are
    | regenerated. Per *locale*, not per project: a project selling in Lisbon to
    | Portuguese, English and Russian speakers is visible or invisible three
    | separate times, and a score computed only in the market's own language
    | reports 0% while customers arrive from an assistant answering in another.
    | That is not a hypothetical — it is why this setting is shaped this way.
    |
    */

    'prompts_per_locale' => (int) env('VISIBILITY_PROMPTS_PER_LOCALE', 5),

    // Prompts are the measurement's own instrument. Regenerating them every run
    // would mean every week's score is measured against a different ruler, and
    // a trend line across those is meaningless. Long enough to hold still,
    // short enough that a repositioned business is not measured forever on what
    // it used to sell.
    'prompts_stale_after_days' => (int) env('VISIBILITY_PROMPTS_STALE_AFTER_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Budget
    |--------------------------------------------------------------------------
    |
    | Every answer is a paid call to a third-party model, and the count
    | multiplies: prompts × locales × platforms. Five prompts in three locales
    | across four assistants is sixty calls a run, and nothing about the code
    | stops that being six hundred if somebody adds locales.
    |
    | So there is a ceiling, and when it bites the run says which prompts it
    | dropped rather than quietly measuring a subset and reporting the score as
    | though it covered everything.
    |
    */

    'max_answers_per_run' => (int) env('VISIBILITY_MAX_ANSWERS_PER_RUN', 80),

    // Seconds to wait on one assistant. Live mode is documented at up to 120s
    // because the model is actually browsing before it answers.
    'timeout' => (int) env('VISIBILITY_TIMEOUT', 150),

    /*
    |--------------------------------------------------------------------------
    | How the panel reads
    |--------------------------------------------------------------------------
    */

    // Hosts never counted as a competitor brand in the "who gets mentioned
    // instead" table. Directories and review sites are where assistants get
    // their lists; they are not the businesses losing or winning the customer.
    'aggregator_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'VISIBILITY_AGGREGATOR_HOSTS',
        'reddit.com,quora.com,tripadvisor.com,trustpilot.com,yelp.com,facebook.com,instagram.com,'
        .'linkedin.com,youtube.com,wikipedia.org,google.com,maps.google.com,medium.com',
    ))))),

];
