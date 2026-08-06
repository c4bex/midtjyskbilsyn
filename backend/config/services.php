<?php

return [

    'dmr' => [
        'base_url' => env('DMR_LOOKUP_BASE_URL'),
        'token' => env('DMR_LOOKUP_TOKEN'),
        'dataset' => env('DMR_LOOKUP_DATASET', 'full'),
        'timeout' => (int) env('DMR_LOOKUP_TIMEOUT_SECONDS', 5),
    ],

    'ai' => [
        'enabled' => filter_var(env('AI_ENABLED', false), FILTER_VALIDATE_BOOL),
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5.6-sol'),
        'timeout' => (int) env('OPENAI_TIMEOUT_SECONDS', 45),
        'web_search_enabled' => filter_var(env('AI_WEB_SEARCH_ENABLED', false), FILTER_VALIDATE_BOOL),
        'web_allowed_domains' => array_values(array_filter(array_map('trim', explode(',', env('AI_WEB_ALLOWED_DOMAINS', 'retsinformation.dk,fstyr.dk,motorst.dk,skat.dk,virk.dk,borger.dk'))))),
    ],

    'arvo' => [
        'enabled' => filter_var(env('ARVO_ENABLED', false), FILTER_VALIDATE_BOOL),
        'base_url' => env('ARVO_BASE_URL'),
        'token' => env('ARVO_API_TOKEN'),
    ],

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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
