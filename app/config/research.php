<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Keyword source
    |--------------------------------------------------------------------------
    */

    // Which adapter sits behind the KeywordSource port. Both stay in the
    // codebase during the switch: cutting over is a config change, and rolling
    // back is the same change in reverse rather than a revert.
    'source' => env('KEYWORD_SOURCE', 'dataforseo'),

    'ahrefs' => [
        'token' => env('AHREFS_API_TOKEN'),
        'base_url' => env('AHREFS_BASE_URL', 'https://api.ahrefs.com'),
        'timeout' => (int) env('AHREFS_TIMEOUT', 30),
    ],

    'dataforseo' => [
        // Not the account password. DataForSEO issues a separate API password
        // from the API access dashboard, and the account one returns 40100
        // exactly as a typo would.
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),

        // Live SERP is documented at ~6 seconds and keyword endpoints at ~2,
        // but the SERP one goes and fetches a real result page. 30 was fine for
        // Ahrefs, which only ever read its own index.
        'timeout' => (int) env('DATAFORSEO_TIMEOUT', 60),

        // Which of the two phrase-expansion endpoints to ask.
        //
        //   suggestions — keywords whose text contains the seed. What Ahrefs
        //                 matching-terms did, so the pool keeps its meaning.
        //   ideas       — keywords sharing the seed's product *category*.
        //
        // Use suggestions. `ideas` looked like the answer to a thin pool — it
        // returns 50 rows where suggestions returns 9 — and measuring it showed
        // why the row count was the wrong thing to look at. Asked for
        // "limpeza doméstica lisboa" in Portugal, its top results by volume were:
        //
        //   portugal                450,000     tempo lisboa       368,000
        //   jogos santa casa        368,000     benfica jogos      201,000
        //   metro lisboa            135,000
        //
        // The weather, the lottery, and the football. It answers "what is
        // searched in this category and geography", which for a local service
        // business is the whole country's search behaviour — and because the
        // pool is ordered by volume, every one of those outranks the real
        // keywords and fills the month. In English it was subtler and the same:
        // "dry cleaning band", "sneaker cleaning", "cleaning icon".
        //
        // Kept as a setting because a broad-category business may want it, but
        // it is not the fix for a thin pool. The fix measured on this project
        // was the seeds: DataForSEO Labs has no English corpus for Portugal
        // (`available_languages` is `[pt]` alone), so English seeds are answered
        // out of the Portuguese index. Seeded in Portuguese, suggestions
        // returns "empresa de limpeza lisboa" at 1,000/mo — four times the best
        // keyword Ahrefs could find for the same business.
        'keyword_endpoint' => env('DATAFORSEO_KEYWORD_ENDPOINT', 'suggestions'),

        // How long a resolved country stays resolved. These are Google's geo
        // target ids and they do not move; the cache exists so a research run
        // does not spend a round trip re-learning that Portugal is 2620.
        'locations_ttl_days' => (int) env('DATAFORSEO_LOCATIONS_TTL_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Research
    |--------------------------------------------------------------------------
    |
    | How many keywords to pull per seed, and the floor a keyword must clear to
    | become an idea at all. The floor exists because a pool full of 20-a-month
    | long tail is a pool nobody can plan a month from.
    |
    */

    'per_seed_limit' => (int) env('RESEARCH_PER_SEED_LIMIT', 50),

    // How many topics to propose from the brief before measuring them.
    //
    // Generous on purpose: the measuring endpoint charges $0.02 for the task
    // and nothing for phrases it has never heard of, so a proposal that turns
    // out to be invented costs nothing but the tokens that wrote it. Sixty
    // proposed against six angles is ten places to look per angle, and the
    // survivors are whatever real demand there was.
    'angles_per_run' => (int) env('RESEARCH_ANGLES_PER_RUN', 60),

    // How many proposed topics to keep that nobody could put a number on.
    //
    // Measured, and the measurement is the argument. Google Ads prices four of
    // the twenty topics taken off a competitor's published calendar; the other
    // sixteen — "limpeza para expatriados", "gestão de chaves limpeza",
    // "contrato de limpeza doméstica" — come back null. Those are not bad
    // topics. They are the ones a keyword tool cannot see and a person who
    // knows the business can, which is the entire reason for proposing rather
    // than expanding.
    //
    // So a few come through unpriced. A few, not all: they sort last because
    // their opportunity score is zero, so they fill the tail of a month rather
    // than the head of it, and a month made entirely of unmeasurable topics is
    // a month built on nobody's opinion but the model's.
    'unmeasured_angles_kept' => (int) env('RESEARCH_UNMEASURED_ANGLES_KEPT', 10),

    // How many keywords have to survive the floor before a pool is worth
    // planning from. Under this, research fails and says so: a plan built from
    // one keyword is a dashboard that looks broken for a month, with the cause
    // four steps upstream where nobody will look for it.
    'minimum_pool' => (int) env('RESEARCH_MINIMUM_POOL', 3),

    // Which parts of a site's URLs mean "somebody wrote this". Used to tell an
    // article apart from a service page when reading the project's sitemap, so
    // the duplicate check compares topics against topics. Add yours if your
    // posts live somewhere else — a site publishing at /stories/... or
    // /2026/03/... has none of these.
    // How long a topic stays covered.
    //
    // Two different questions hide behind "we already wrote this". Inside this
    // window it is a duplicate and should not be planned at all. Outside it,
    // the subject is fair game again — search results move, prices change, and
    // a three-year-old post is a refresh candidate rather than a reason never
    // to write about something again.
    'covered_for_months' => (int) env('TOPIC_COVERED_FOR_MONTHS', 12),

    // How close two topics have to be to count as the same subject, as cosine
    // distance on text-embedding-3-small.
    //
    // Measured, not guessed. Against a real site: "carpet cleaning lisbon"
    // against that site's own "Carpet Cleaning in Lisbon: Prices" is 0.27, the
    // Portuguese edition of the same article is 0.30, and the nearest genuinely
    // different subject — upholstery cleaning — is 0.38. A first guess of 0.25
    // caught neither of the duplicates.
    //
    // The bands overlap, which is why there are two numbers and a model.
    // Measured on the same site: a true duplicate lands at 0.27, its own
    // translation at 0.30, and "house cleaning" against "post-renovation
    // cleaning" — a different service — at 0.31. Nothing separates those with
    // one threshold.
    //
    // So: below `certain` it is a duplicate and nobody is asked. Between
    // `certain` and `possible` a model is shown both titles and decides. Above
    // `possible` the subjects are unrelated and no call is made.
    'same_topic_certain' => (float) env('SAME_TOPIC_CERTAIN', 0.28),
    'same_topic_possible' => (float) env('SAME_TOPIC_POSSIBLE', 0.42),

    // The same question asked of two ideas in the same month, which is a
    // different distribution and needs its own numbers.
    //
    // Both come from one keyword source in one language, so everything a local
    // business could write about sits close together. Measured on this project:
    //
    //   duplicates    "cleaning service lisbon" / "cleaning services lisbon"  0.02
    //                 "house cleaning lisbon"   / "home cleaning lisbon"      0.04
    //                 "cleaning lisbon"         / "lisbon cleaning"           0.10
    //   different     "cleaning services"       / "cleaning lady"             0.11
    //                 "cleaning services"       / "deep cleaning"             0.13
    //                 "cleaning services"       / "carpet cleaning"           0.17
    //
    // The site thresholds applied here would have called deep cleaning a
    // duplicate of general cleaning and left a month of four articles. The
    // bands still overlap at 0.11, which is what the model in between is for.
    'planned_topic_certain' => (float) env('PLANNED_TOPIC_CERTAIN', 0.06),
    'planned_topic_possible' => (float) env('PLANNED_TOPIC_POSSIBLE', 0.25),

    // What to do with a page whose sitemap gives no `<lastmod>`.
    //
    // There is no way to age it out, so it is either covered forever or never.
    // Covered is the default because it is what the operator asked for — do not
    // write the same thing twice — and the escape hatch is this setting rather
    // than a threshold so tight that the check never fires at all.
    'block_undated_pages' => (bool) env('BLOCK_UNDATED_PAGES', true),

    'article_path_markers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'ARTICLE_PATH_MARKERS',
            'journal,blog,news,post,posts,article,articles,guide,guides,artigo,artigos,noticias,blogue',
        )),
    ))),
    'minimum_volume' => (int) env('RESEARCH_MINIMUM_VOLUME', 50),
    'maximum_difficulty' => (int) env('RESEARCH_MAXIMUM_DIFFICULTY', 70),

    /*
    |--------------------------------------------------------------------------
    | Planning
    |--------------------------------------------------------------------------
    |
    | Which channels a planned unit is expected to be repurposed to. Recorded on
    | the unit in phase 4 so the month's cost is estimable; the derivatives
    | themselves are built in phase 8.
    |
    */

    'default_derivative_channels' => ['linkedin', 'x'],

];
