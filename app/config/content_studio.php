<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content Studio
|--------------------------------------------------------------------------
|
| Product rules for the assistant-driven month, in the shape config/social.php
| already uses for the Threads contour: almost nothing reads env(), because a
| selection bar a deployment can lower from its own environment is not a bar.
|
| The two numbers that decide what the Studio costs and what it will ship are
| `draft.candidates` and `draft.selection_floor`, and they are the same trade
| §4.3 makes for the social contour — tokens are cheaper than a weak post, and
| the way to spend them is to write several and keep one. The Studio wrote one
| and kept it, which is why every draft it produced was a first attempt.
|
*/

return [

    'draft' => [

        /*
         * How many candidates to write per channel, and the bounds it is
         * clamped into.
         *
         * Lower than the contour's eight on purpose. The contour writes for one
         * channel and publishes without a human; the Studio writes for three
         * and stops at a draft somebody reads, so the pool buys a smaller
         * improvement over a wider fan-out — four candidates on three channels
         * is already twelve calls for one idea. Two is the floor because one is
         * not a pool, and a deployment that edits this to one has turned the
         * selection step back into the generator this release replaced.
         */
        'candidates' => 4,
        'min_candidates' => 2,
        'max_candidates' => 8,

        /*
         * The bar a candidate has to clear, on the 0–100 of ChannelPostScore.
         *
         * Exactly PostFormat::BASE, which is what a competent post with nothing
         * for or against it scores. So the bar means "do at least one thing
         * this channel rewards" rather than "be extraordinary", and a pool
         * where nothing clears it says something real about the pool.
         *
         * Unlike the contour, falling under the bar does not leave the slot
         * empty: the Studio's output is a draft an operator reads, and an
         * operator who opens the week to three missing posts and no draft to
         * react to cannot do anything about it. The best candidate is written
         * with its score and its findings attached instead. See
         * ContentStudioAssistant::chooseCandidate().
         */
        'selection_floor' => 50,

    ],

    /*
    |--------------------------------------------------------------------------
    | What a month is made of
    |--------------------------------------------------------------------------
    |
    | Shares per PostKind, in percentage points, and the one number that is a
    | ceiling rather than a target.
    |
    | These are editorial positions and a deployment may hold different ones —
    | which is why they are here rather than in the enum — but the shape is not
    | arbitrary. Teaching is the largest share because it is what earns the
    | right to be read; opinion is second because it is what the conversational
    | channels actually reward and what the previous plans had none of; proof is
    | what stops the teaching reading as theory; behind-the-scenes is the
    | cheapest to produce honestly and the hardest to fake.
    |
    | `offer` is a ceiling. One post in ten is already more selling than most
    | accounts get away with, and see ContentMix for why under-shooting it is
    | not a defect. A deployment that raises this is buying reach with it.
    |
    */

    'mix' => [
        'shares' => [
            'how_to' => 30,
            'take' => 25,
            'proof' => 20,
            'behind' => 15,
            'offer' => 10,
        ],
        'offer_ceiling' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | The panel renderer
    |--------------------------------------------------------------------------
    |
    | A Remotion service inside the compose network that draws slides from React
    | components. Absent means not configured, which is a skip rather than a
    | failure: a deployment without it still writes every post, and a carousel
    | there ships the way it always did.
    |
    | The timeout is generous because a cold container bundles before it can
    | draw. It is still far below the step's own, so a wedged renderer fails one
    | picture rather than a batch.
    |
    */

    'renderer' => [
        'url' => env('RENDERER_URL'),
        'timeout' => (int) env('RENDERER_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | What each channel's picture is shaped like
    |--------------------------------------------------------------------------
    |
    | The platforms' numbers rather than ours, which is why these are the one
    | part of this file a deployment may plausibly need to change: a channel
    | that changes its crop changes it for everybody, and waiting for a release
    | to follow it would mean a month of badly cropped posts.
    |
    | 1080×1350 is Instagram's 4:5, the tallest thing the feed will show
    | uncropped. 1080×1080 is the square Threads renders without cutting. X
    | shows 16:9 in the timeline. The old code sent 1200×630 — an Open Graph
    | card — to all three.
    |
    */

    'images' => [
        'threads' => [
            'width' => (int) env('SOCIAL_IMAGE_THREADS_WIDTH', 1080),
            'height' => (int) env('SOCIAL_IMAGE_THREADS_HEIGHT', 1080),
        ],
        'x' => [
            'width' => (int) env('SOCIAL_IMAGE_X_WIDTH', 1200),
            'height' => (int) env('SOCIAL_IMAGE_X_HEIGHT', 675),
        ],
        'instagram' => [
            'width' => (int) env('SOCIAL_IMAGE_INSTAGRAM_WIDTH', 1080),
            'height' => (int) env('SOCIAL_IMAGE_INSTAGRAM_HEIGHT', 1350),
        ],
        'default' => [
            'width' => (int) env('SOCIAL_IMAGE_WIDTH', 1080),
            'height' => (int) env('SOCIAL_IMAGE_HEIGHT', 1080),
        ],
    ],

];
