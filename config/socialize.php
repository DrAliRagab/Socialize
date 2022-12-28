<?php

// config for DrAliRagab/Socialize
return [
    'facebook' => [
        'default' => [
            'app_id' => env('FACEBOOK_APP_ID'),
            'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v15.0'),
            'page_id' => env('FACEBOOK_PAGE_ID'),
            'page_access_token' => env('FACEBOOK_PAGE_ACCESS_TOKEN'),
        ],
    ],

    'instagram' => [
        'default' => [
            'graph_version' => env('INSTAGRAM_GRAPH_VERSION', 'v15.0'),
            'user_access_token' => env('INSTAGRAM_USER_ACCESS_TOKEN'),
            'instagram_account_id' => env('INSTAGRAM_ACCOUNT_ID'),
        ],
    ],

    'twitter' => [
        'default' => [
            'app_consumer_key' => env('TWITTER_CONSUMER_KEY'),
            'app_consumer_secret' => env('TWITTER_CONSUMER_SECRET'),
            'account_access_token' => env('TWITTER_ACCOUNT_ACCESS_TOKEN'),
            'account_access_token_secret' => env('TWITTER_ACCOUNT_ACCESS_TOKEN_SECRET'),
        ],
    ],

    'model_columns' => [
        'message_column' => 'title',
        'photo_column' => 'image',
    ],
];
