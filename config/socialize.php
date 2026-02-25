<?php

declare(strict_types=1);

return [
    'default_profile' => env('SOCIALIZE_DEFAULT_PROFILE', 'default'),

    'http' => [
        'timeout'         => (int)env('SOCIALIZE_HTTP_TIMEOUT', 120),
        'connect_timeout' => (int)env('SOCIALIZE_HTTP_CONNECT_TIMEOUT', 30),
        'retries'         => (int)env('SOCIALIZE_HTTP_RETRIES', 1),
        'retry_sleep_ms'  => (int)env('SOCIALIZE_HTTP_RETRY_SLEEP_MS', 150),
    ],

    'temporary_media' => [
        'disk'       => env('SOCIALIZE_TEMP_MEDIA_DISK', 'public'),
        'directory'  => env('SOCIALIZE_TEMP_MEDIA_DIRECTORY', 'socialize-temp'),
        'visibility' => env('SOCIALIZE_TEMP_MEDIA_VISIBILITY', 'public'),
    ],

    'model_columns' => [
        'message' => env('SOCIALIZE_MODEL_MESSAGE_COLUMN', 'title'),
        'link'    => env('SOCIALIZE_MODEL_LINK_COLUMN', 'url'),
        'image'   => env('SOCIALIZE_MODEL_IMAGE_COLUMN', 'image'),
    ],

    'providers' => [
        'facebook' => [
            'base_url'      => env('SOCIALIZE_FACEBOOK_BASE_URL', 'https://graph.facebook.com'),
            'graph_version' => env('SOCIALIZE_FACEBOOK_GRAPH_VERSION', 'v25.0'),
            'profiles'      => [
                'default' => [
                    'page_id'      => env('SOCIALIZE_FACEBOOK_PAGE_ID'),
                    'access_token' => env('SOCIALIZE_FACEBOOK_ACCESS_TOKEN'),
                ],
            ],
        ],

        'instagram' => [
            'base_url'      => env('SOCIALIZE_INSTAGRAM_BASE_URL', 'https://graph.facebook.com'),
            'graph_version' => env('SOCIALIZE_INSTAGRAM_GRAPH_VERSION', 'v25.0'),
            'profiles'      => [
                'default' => [
                    'ig_id'        => env('SOCIALIZE_INSTAGRAM_IG_ID'),
                    'access_token' => env('SOCIALIZE_INSTAGRAM_ACCESS_TOKEN'),
                ],
            ],
        ],

        'twitter' => [
            'base_url' => env('SOCIALIZE_TWITTER_BASE_URL', 'https://api.x.com'),
            'profiles' => [
                'default' => [
                    'bearer_token' => env('SOCIALIZE_TWITTER_BEARER_TOKEN'),
                ],
            ],
        ],

        'linkedin' => [
            'base_url' => env('SOCIALIZE_LINKEDIN_BASE_URL', 'https://api.linkedin.com'),
            'profiles' => [
                'default' => [
                    'author'       => env('SOCIALIZE_LINKEDIN_AUTHOR'),
                    'access_token' => env('SOCIALIZE_LINKEDIN_ACCESS_TOKEN'),
                    'version'      => env('SOCIALIZE_LINKEDIN_VERSION', '202602'),
                ],
            ],
        ],
    ],
];
