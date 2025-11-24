<?php

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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    // Tap Payments - DEPRECATED for payments (migrated to Paylink)
    // Still used for withdrawals only
    'tap' => [
        'public_key' => env('TAP_PUBLIC_KEY'),
        'secret_key' => env('TAP_SECRET_KEY'),
        'webhook_secret' => env('TAP_WEBHOOK_SECRET'),
        'base_url' => env('TAP_BASE_URL', 'https://api.tap.company/v2'),
    ],

    'persona' => [
        'api_key' => env('PERSONA_API_KEY'),
        'template_id' => env('PERSONA_TEMPLATE_ID'),
        'environment_id' => env('PERSONA_ENVIRONMENT_ID'),
        'webhook_secret' => env('PERSONA_WEBHOOK_SECRET'),
        // Use official API hostname; path /api/v1 is appended in the service
        'base_url' => env('PERSONA_BASE_URL', 'https://api.withpersona.com/api/v1'),
    ],

    'cloudflare' => [
        'account_id' => env('CLOUDFLARE_ACCOUNT_ID'),
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'account_hash' => env('CLOUDFLARE_ACCOUNT_HASH'),
        'images_base' => env('CLOUDFLARE_IMAGES_BASE', 'https://imagedelivery.net'),
    ],

    'tiktok' => [
        'client_key' => env('TIKTOK_CLIENT_KEY'),
        'client_secret' => env('TIKTOK_CLIENT_SECRET'),
        'base_url' => env('TIKTOK_BASE_URL', 'https://open-api.tiktok.com'),
        'redirect_uri' => env('APP_URL') . '/api/v1/tiktok/callback',
    ],

    'turnstile' => [
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'site_key' => env('TURNSTILE_SITE_KEY'),
    ],

    'discord_bot' => [
        'webhook_url' => env('DISCORD_BOT_WEBHOOK_URL'),
        'webhook_secret' => env('DISCORD_BOT_WEBHOOK_SECRET'),
    ],

    'discord' => [
        'client_id' => env('DISCORD_CLIENT_ID'),
        'client_secret' => env('DISCORD_CLIENT_SECRET'),
        'redirect_uri' => env('DISCORD_REDIRECT_URI', env('APP_URL') . '/api/v1/auth/discord/callback'),
        'scopes' => env('DISCORD_OAUTH_SCOPES', 'identify email guilds.join'),
        'bot_webhook_url' => env('DISCORD_BOT_WEBHOOK_URL'),
        'guild_id' => env('DISCORD_GUILD_ID'), // Server/Guild ID for auto-joining
    ],

    'paylink' => [
        'base_url' => env('PAYLINK_BASE_URL', 'https://restpilot.paylink.sa'),
        'api_id' => env('PAYLINK_API_ID'),
        'secret' => env('PAYLINK_API_SECRET'),
    ],

];
