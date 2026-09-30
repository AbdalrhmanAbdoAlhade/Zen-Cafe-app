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
       'hyperpay' => [
        'base_url'            => rtrim((string) env('HYPERPAY_BASE_URL', 'https://eu-test.oppwa.com'), '/'),
        'access_token'        => env('HYPERPAY_ACCESS_TOKEN'),
        'entity_id'           => env('HYPERPAY_ENTITY_ID'),
        'currency'            => env('HYPERPAY_CURRENCY', 'SAR'),
        'test_mode'           => (bool) env('HYPERPAY_TEST_MODE', true),
        'merchant_url'        => env('HYPERPAY_MERCHANT_URL'),
        'frontend_return_url' => env('HYPERPAY_FRONTEND_RETURN_URL'),
        'billing'             => [
            'email'    => env('HYPERPAY_DEFAULT_EMAIL'),
            'street'   => env('HYPERPAY_DEFAULT_STREET', 'Riyadh'),
            'city'     => env('HYPERPAY_DEFAULT_CITY', 'Riyadh'),
            'state'    => env('HYPERPAY_DEFAULT_STATE', 'Riyadh'),
            'country'  => env('HYPERPAY_DEFAULT_COUNTRY', 'SA'),
            'postcode' => env('HYPERPAY_DEFAULT_POSTCODE', '11564'),
        ],
    ],
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
