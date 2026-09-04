<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Our OAuth client, which identifies this application to Google. The grant
     * it obtains belongs to a project and lives in `project_integrations` —
     * these three values are the same for every project and are not a
     * connection to anybody's data on their own.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Must match a redirect URI registered on the OAuth client exactly,
        // including scheme and port. Google compares it as a string.
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/integrations/google/callback'),
        /*
         * Where Google returns people who signed *in* with it, which is a
         * different flow and therefore a different registered redirect URI —
         * the integration above asks for Search Console and Analytics scopes
         * and keeps a refresh token; sign-in asks for a name and an address and
         * keeps neither.
         *
         * Null by default, and the controller builds the URL from the route
         * when it is: one fewer variable to drift out of step with the route
         * table. Set it only where the public URL is not the one `route()`
         * would build.
         */
        'auth_redirect' => env('GOOGLE_AUTH_REDIRECT_URI'),
    ],

    /*
     * Threads, for publishing and listening (§9).
     *
     * The credentials of a Meta app with Threads API access. An installation
     * without them cannot connect a Threads channel at all, which is a state
     * the settings screen reports rather than a crash: every other integration
     * here degrades the same way.
     *
     * `webhook_secret` is the app secret Meta signs inbound events with
     * (`X-Hub-Signature-256`), and `webhook_verify_token` is the string echoed
     * back during the subscription handshake. They are separate from the OAuth
     * pair because Meta lets them be, and because a project that only listens
     * needs one and not the other.
     */
    'threads' => [
        'client_id' => env('THREADS_APP_ID'),
        'client_secret' => env('THREADS_APP_SECRET'),
        // Must match a redirect URI registered on the app exactly, including
        // scheme and port — Meta compares it as a string, as Google does.
        'redirect' => env('THREADS_REDIRECT_URI', env('APP_URL').'/integrations/threads/callback'),
        'webhook_secret' => env('THREADS_WEBHOOK_SECRET'),
        'webhook_verify_token' => env('THREADS_WEBHOOK_VERIFY_TOKEN'),
        /*
         * The scopes of §2. `threads_keyword_search` is listed but is the one
         * §11.2 flags as needing separate approval: without it, listening
         * degrades to the project's own posts, and the adapter has to be able
         * to tell that apart from an empty answer.
         */
        'scopes' => [
            'threads_basic',
            'threads_content_publish',
            'threads_manage_insights',
            'threads_manage_replies',
            'threads_keyword_search',
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
