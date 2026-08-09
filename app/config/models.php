<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Models and what they cost
|--------------------------------------------------------------------------
|
| §9 of the spec defers the choice of model per step "until there is metering
| data". That deferral only works if the choice is one edit here rather than a
| change to a step class — so steps name a *role* ("draft", "factcheck") and
| this file says which model plays it.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | The vocabulary steps use. A step asks for `draft`; which provider and
    | model that means is decided here, and changing it changes no code.
    |
    | `provider` is a key of config('laragent.providers') — Anthropic's is
    | called `claude` there, not `anthropic`, and naming it wrong fails the
    | step with "Provider not found" rather than falling back to anything.
    |
    */

    'default_role' => 'draft',

    'roles' => [
        'draft' => [
            'provider' => env('MODEL_DRAFT_PROVIDER', 'openai'),
            'model' => env('MODEL_DRAFT', 'gpt-5'),
        ],
        // §9 puts the good model on the outline as well as the fact-check:
        // both are places where being wrong is structural rather than stylistic.
        'outline' => [
            'provider' => env('MODEL_OUTLINE_PROVIDER', 'openai'),
            'model' => env('MODEL_OUTLINE', 'gpt-5'),
        ],
        // Cheap and short: classification, extraction, tidying.
        'utility' => [
            'provider' => env('MODEL_UTILITY_PROVIDER', 'openai'),
            'model' => env('MODEL_UTILITY', 'gpt-5-mini'),
        ],
        // Where being wrong is expensive — §1 makes this a required stage for
        // cryptoroute, which is YMYL.
        'factcheck' => [
            'provider' => env('MODEL_FACTCHECK_PROVIDER', 'openai'),
            'model' => env('MODEL_FACTCHECK', 'gpt-5'),
        ],

        /*
         * The pool of post candidates of §4.3, and the most expensive tier
         * that has a price under the current list.
         *
         * > `draft_candidates` генерирует 5–10 кандидатов и оставляет один.
         * > Токены дешевле охвата: цена плохого поста — просевшее окно показа
         * > на недели вперёд. Это единственное место, где сознательно тратится
         * > дорогая модель.
         *
         * Its own role rather than `draft` for two reasons, and the second is
         * the one that made it a role. The first is that the sentence above is
         * a decision about this step alone: a project that wants the good model
         * writing eight posts and the cheap one writing replies says so in two
         * lines here and changes no code (§9).
         *
         * The second is §8. The unit that gets costed is the *published* post,
         * and at one in eight the post costs eight generations — "отчёт,
         * считающий вызовы, соврёт в разы". `pipeline:cost` groups by role, so
         * folding the pool into `draft` would bury the price of selection
         * inside the price of writing, and the one number §12's sixth exit
         * criterion asks for — what a published post costs — would be
         * unrecoverable from the report. A separate role makes selection a line
         * of its own.
         *
         * `gpt-5.6-sol` and not `terra`: this is where the expense is meant to
         * be, and it is priced in version 2 below, so the report shows real
         * micro-USD rather than the $0.0000 that an unpriced model produces.
         */
        'social_candidates' => [
            'provider' => env('MODEL_SOCIAL_CANDIDATES_PROVIDER', 'openai'),
            'model' => env('MODEL_SOCIAL_CANDIDATES', 'gpt-5.6-sol'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Its own block, not a role: an embedding is priced per input token with no
    | output side, and the model is chosen for stability rather than quality —
    | changing it invalidates every vector already stored, which is a migration
    | rather than a config edit.
    |
    */

    'embeddings' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1536),
        'timeout' => (int) env('EMBEDDING_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Price list
    |--------------------------------------------------------------------------
    |
    | Micro-USD per million tokens, so a price is an integer and a month of
    | fractional-cent steps does not become an argument about rounding.
    |
    | `version` is the point of this block. Every run records the version it was
    | priced under, so re-pricing publishes a *new* version rather than silently
    | restating what last quarter cost (§3.4). Never edit a published version's
    | numbers in place — add a version and leave the old one here, because runs
    | already reference it.
    |
    | Input and output only. Both providers also price cached input (a tenth of
    | the rate) and OpenAI charges a long-context tier above 272k tokens, and
    | neither is modelled here — `ModelResponse` reports one input count with no
    | breakdown, so a schema for them would be arithmetic on numbers nobody
    | measures. The effect is that these figures are a ceiling: real spend on a
    | cache-heavy workload is lower than what the report shows.
    |
    */

    'prices' => [

        'version' => (int) env('MODEL_PRICE_LIST_VERSION', 2),

        'versions' => [

            // Version 1 is what the first runs were priced under, and it is
            // left exactly as it was. It never carried a price for
            // `gpt-5.6-terra`, which is why those runs recorded zero — editing
            // the number in now would restate history as though we had known
            // it at the time, which is the one thing the versioning exists to
            // prevent (§3.4).
            1 => [
                'gpt-5' => ['input' => 1_250_000, 'output' => 10_000_000],
                'gpt-5-mini' => ['input' => 250_000, 'output' => 2_000_000],

                'claude-opus-5' => ['input' => 5_000_000, 'output' => 25_000_000],
                'claude-sonnet-5' => ['input' => 3_000_000, 'output' => 15_000_000],
                'claude-haiku-4-5-20251001' => ['input' => 1_000_000, 'output' => 5_000_000],
                // The fake gateway's model, so tests exercise the same costing
                // path as production instead of a branch that skips it.
                'fake-model' => ['input' => 1_000_000, 'output' => 2_000_000],
            ],

            // Version 2 — read from the providers' own pricing pages on
            // 7 August 2026:
            //   developers.openai.com/api/docs/pricing
            //   platform.claude.com/docs/en/about-claude/pricing
            //
            // Standard tier throughout. Batch is half, and OpenAI's fast mode
            // is double; neither is what this engine calls.
            2 => [
                // The GPT-5.6 family. `terra` is what every role runs on here
                // by default, and it had no entry at all before this version —
                // which is why the cost report read $0.0000 next to a real
                // token count.
                'gpt-5.6-terra' => ['input' => 2_000_000, 'output' => 12_000_000],
                'gpt-5.6-sol' => ['input' => 5_000_000, 'output' => 30_000_000],
                'gpt-5.6-luna' => ['input' => 200_000, 'output' => 1_200_000],

                'gpt-5' => ['input' => 1_250_000, 'output' => 10_000_000],
                'gpt-5-mini' => ['input' => 250_000, 'output' => 2_000_000],

                'claude-fable-5' => ['input' => 10_000_000, 'output' => 50_000_000],
                'claude-opus-5' => ['input' => 5_000_000, 'output' => 25_000_000],
                // Introductory pricing, and it ends. $2/$10 through
                // 31 August 2026, then $3/$15 — which is what version 1 above
                // already carried, having been written for the standard rate.
                // On 1 September this becomes wrong: add a version 3 with
                // 3_000_000/15_000_000 and bump MODEL_PRICE_LIST_VERSION,
                // rather than editing this line.
                'claude-sonnet-5' => ['input' => 2_000_000, 'output' => 10_000_000],
                'claude-haiku-4-5-20251001' => ['input' => 1_000_000, 'output' => 5_000_000],

                // Priced per input token with no output side. Listed for
                // completeness and read by nothing yet: the embedding gateway
                // does not meter, so the corpus indexing in phase 9 is real
                // spend that no cost report shows. The number is here so that
                // whoever wires the meter has it.
                'text-embedding-3-small' => ['input' => 20_000, 'output' => 0],

                'fake-model' => ['input' => 1_000_000, 'output' => 2_000_000],
            ],

        ],
    ],

];
