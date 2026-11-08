<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_CHAT_ID'),
        'enabled' => env('TELEGRAM_NOTIFICATIONS_ENABLED', true),
        'api_url' => env('TELEGRAM_API_URL', 'https://api.telegram.org'),
        'allowed_user_ids' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('TELEGRAM_ALLOWED_USER_IDS', ''))),
            fn (string $id): bool => $id !== '',
        )),
        'poll_timeout' => (int) env('TELEGRAM_POLL_TIMEOUT', 30),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
    ],

    'github' => [
        'app_id' => env('GITHUB_APP_ID'),
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect_uri' => env('GITHUB_REDIRECT_URI', 'http://localhost:8000/api/github/callback'),
        'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', 'http://localhost:8000/api/google/callback'),
        'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
        'live_model' => env('GEMINI_LIVE_MODEL', 'gemini-3.8-live'),
        'tts_model' => env('GEMINI_TTS_MODEL', 'gemini-3.8-flash-tts'),
        'voice' => env('GEMINI_VOICE', 'Aoede'),
    ],

    'groq' => [
        'api_key' => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),
    ],

    'ollama' => [
        'api_key' => env('OLLAMA_API_KEY'),
        'model' => env('OLLAMA_MODEL', 'gpt-oss:120b'),
        // Optional: overrides the default https://ollama.com endpoint
        // (e.g. http://localhost:11434 for a local daemon).
        'base_url' => env('OLLAMA_BASE_URL'),
    ],

    'sarvam' => [
        'api_key' => env('SARVAM_API_KEY'),
        'model' => env('SARVAM_TTS_MODEL', 'bulbul:v3'),
        'speaker' => env('SARVAM_SPEAKER', 'meera'),
    ],
];
