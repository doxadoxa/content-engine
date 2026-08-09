<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Social presence
|--------------------------------------------------------------------------
|
| Product rules, not deployment settings — which is why almost nothing here
| reads env(). A ceiling that a deployment can raise from its own environment
| is not a ceiling; it is a suggestion, and the one thing §5 is unambiguous
| about is that the budget is a ceiling and never a plan. "Недобор допустим, перебор —
| нет": an empty slot is a valid result of a planning run (§4.3), and the
| instinct of a content engine to fill a calendar is exactly what burns an
| account.
|
| The numbers that are the platform's rather than ours are marked as such. Those
| are facts about Threads that we are allowed to learn from the API at runtime;
| the rest are decisions.
|
| Two settings do read env(), and each says on its own line why it is not a
| product rule: `enabled`, which is whether this installation runs the feature
| at all, and `threads.reply_to_foreign`, which records something about the
| platform we have not been able to verify.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Whether this deployment runs the social presence at all
    |--------------------------------------------------------------------------
    |
    | The exception to the paragraph above, and the reason it is an exception is
    | worth stating rather than leaving to be inferred: everything else in this
    | file is a product rule — what the engine believes about publishing to
    | Threads — and a deployment has no standing to disagree with it. This is a
    | deployment question. Whether a Meta app exists, whether an operator ever
    | connected an account, whether anybody here wants a presence on Threads:
    | none of that is something §1–§11 can answer, because the answer is
    | different per installation and changes when somebody signs up for
    | something.
    |
    | Off, the feature is absent rather than idle. Five contours stop being
    | scheduled, the webhook and the OAuth routes stop existing, Threads is not
    | offered as a channel type, and the two screens that only make sense with a
    | connected account are not in the navigation. An installation with no
    | Threads app should not run hourly to discover that, nor offer an operator
    | doors that open onto empty rooms.
    |
    | Default off, because that is the truthful default: a fresh installation
    | has no Meta app. Turning it on is documented in `.env.example` beside the
    | credentials it needs.
    |
    */

    'enabled' => (bool) env('SOCIAL_PRESENCE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Threads (§2)
    |--------------------------------------------------------------------------
    |
    | What the platform will accept, and what one step is allowed to spend of
    | it. Publishing is two phase and counted in actions rather than in posts,
    | so a chain of three segments costs three of the daily 250 — which is the
    | second reason a chain has to be justified rather than assumed.
    |
    */

    'threads' => [
        'text_limit' => 500,                 // §2 — the API's own limit
        'max_segments' => 3,                 // a chain is an exception a step justifies (§2)
        'publish_actions_per_day' => 250,    // §2; refreshed from the API when it answers with a limit
        'search_requests_per_day' => 2200,   // §2; empty answers do not count

        /*
         * §11.1, open and staying open.
         *
         * Whether `reply_to_id` may target somebody else's post is undocumented
         * and unverified — the reply-management docs describe only incoming
         * replies to our own. Until it is checked on a live account the engage
         * contour is human-assisting: the engine finds the conversation and
         * writes the draft, a person sends it. It is env-backed because it
         * records a fact about the platform rather than a decision of ours, and
         * the day the platform answers, it is a config change and not a
         * rewrite.
         */
        'reply_to_foreign' => (bool) env('THREADS_REPLY_TO_FOREIGN', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drafting one slot (§4.3, §2)
    |--------------------------------------------------------------------------
    |
    | The pool size is the only number here that costs money, and it is the one
    | §4.3 states outright: "генерирует 5–10 кандидатов и оставляет один". Eight
    | sits in the middle of that range. Going lower buys a cheaper run and a
    | worse post; going higher buys diminishing variety, because the angles of
    | §2 that actually work can be counted on one hand.
    |
    | `min_candidates` and `max_candidates` are the spec's own bounds, kept as
    | numbers so a deployment that edits `candidates` is clamped rather than
    | quietly allowed to run a pool of one — a pool of one is not selection, and
    | selection is the whole reason this pipeline is not a generator (§1).
    |
    | The rest are the deterministic guard's thresholds. They live here and not
    | in the step because they are product rules: what counts as a bare link is
    | a judgement about §2's format, not a tuning knob.
    |
    */
    'draft' => [
        'candidates' => 8,
        'min_candidates' => 5,
        'max_candidates' => 10,

        /*
         * How many words besides the URL make a link "обёрнутая в мысль".
         *
         * §2: "Ссылки сами по себе больше не штрафуются, но ссылка, обёрнутая в
         * мысль или вопрос, живёт лучше." So the guard refuses the bare link
         * and nothing else about links — a threshold rather than a ban, because
         * banning links would refuse the one shape the spec says works.
         */
        'link_min_words' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | The governor (§4.3)
    |--------------------------------------------------------------------------
    |
    | A ceiling and a floor, because both failures are real. Above the ceiling
    | the account pays for it in reach for weeks; below the floor the presence
    | quietly dies, and §4.3 asks for an alert to the operator rather than for
    | the engine to make something up to publish.
    |
    | The duty window is the "next 60–90 minutes" of §4.3 in one number: a slot
    | only stands where the operator is available for the whole of it, because
    | the algorithm weighs the speed of replies in the first hour.
    |
    */

    'governor' => [
        'weekly_ceiling' => 5,
        'daily_ceiling' => 2,
        'weekly_floor' => 2,
        'duty_window_minutes' => 90,         // §4.3

        /*
         * The other half of the ceiling: "trailing reply rate ниже порога →
         * срезает частоту и поднимает планку отбора".
         *
         * Replies per impression, over `trailing_days` of `project_states`.
         * One percent is the line because §1's first fact is that the algorithm
         * reads an account's historical reply rate and sets the starting
         * display window from it — below that the account's own history is
         * telling the platform to show the next post to fewer people, and
         * publishing five times into a narrowing window is how §10's "аккаунт
         * под автоматикой" actually happens.
         *
         * `throttled_weekly_ceiling` equals `weekly_floor` on purpose. Cutting
         * frequency bottoms out at the floor rather than at zero: §4.3 pairs
         * the ceiling with a floor precisely because presence dying is also a
         * failure, so a weak week is planned quieter, not planned away.
         *
         * The selection bar is the second half and it is not optional — fewer
         * slots filled by the same candidates is a smaller version of the same
         * week. Weights are the 0–100 of `SignalWeight`.
         *
         * `throttled_selection_floor` is `PostFormat::BASE + SUBSTANCE`, and
         * the arithmetic is the point rather than the number. §2 names three
         * shapes that work and `PostFormat` scores each of them as one bonus on
         * top of a base that is exactly the ordinary floor; setting the raised
         * bar at the smallest of those bonuses makes a throttled week mean "at
         * least one of §2's working shapes" instead of "nothing wrong with it".
         *
         * It was 70, which is higher than any single shape could reach — a
         * question scored 68, an observation with a figure 64, an image with a
         * caption 50 — so a weak week accepted only a post that happened to be
         * two shapes at once and otherwise published nothing. That is not a
         * higher bar, it is silence with a bar's paperwork, and since
         * `throttled_weekly_ceiling` equals `weekly_floor` the same week then
         * tripped the floor alert by construction.
         *
         * The relationship is pinned by a test rather than left as a comment,
         * because the two numbers live in different files.
         */
        'trailing_days' => 28,
        'min_measured_days' => 7,
        'weak_reply_rate' => 0.01,
        'throttled_weekly_ceiling' => 2,
        'selection_floor' => 50,
        'throttled_selection_floor' => 64,
    ],

];
