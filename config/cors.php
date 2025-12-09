<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([
        // Production domains
        'https://nxoland.com',
        'https://www.nxoland.com',
        'https://bid.nxoland.com',
        // Development/staging (from environment variable)
        // Make sure to set FRONTEND_URL in production (.env file)
        env('FRONTEND_URL'),
        // Always allow localhost for development
        'http://localhost:5173',
        'http://localhost:3000',
        'http://localhost:3001', // Bidding system dev server
    ]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    /*
    |--------------------------------------------------------------------------
    | CORS Preflight Options
    |--------------------------------------------------------------------------
    |
    | Determines how long the browser can cache preflight OPTIONS requests.
    | Set to 0 to disable caching.
    |
    */
    'max_age' => 0,

    'supports_credentials' => true,

];
