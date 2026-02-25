<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Tests;

use DrAliRagab\Socialize\SocializeServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            SocializeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.url', 'https://example.test');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.public', [
            'driver'     => 'local',
            'root'       => storage_path('app/public'),
            'url'        => 'https://example.test/storage',
            'visibility' => 'public',
        ]);

        $app['config']->set('socialize.default_profile', 'default');
        $app['config']->set('socialize.http.timeout', 5);
        $app['config']->set('socialize.http.connect_timeout', 5);
        $app['config']->set('socialize.http.retries', 1);
        $app['config']->set('socialize.http.retry_sleep_ms', 1);
        $app['config']->set('socialize.temporary_media.disk', 'public');
        $app['config']->set('socialize.temporary_media.directory', 'socialize-temp');
        $app['config']->set('socialize.temporary_media.visibility', 'public');

        $app['config']->set('socialize.providers.facebook', [
            'base_url'      => 'https://graph.facebook.com',
            'graph_version' => 'v25.0',
            'profiles'      => [
                'default' => [
                    'page_id'      => '12345',
                    'access_token' => 'fb-token',
                ],
                'missing-token' => [
                    'page_id' => '12345',
                ],
            ],
        ]);

        $app['config']->set('socialize.providers.instagram', [
            'base_url'      => 'https://graph.facebook.com',
            'graph_version' => 'v25.0',
            'profiles'      => [
                'default' => [
                    'ig_id'        => '98765',
                    'access_token' => 'ig-token',
                ],
            ],
        ]);

        $app['config']->set('socialize.providers.twitter', [
            'base_url' => 'https://api.x.com',
            'profiles' => [
                'default' => [
                    'bearer_token' => 'x-token',
                ],
            ],
        ]);

        $app['config']->set('socialize.providers.linkedin', [
            'base_url' => 'https://api.linkedin.com',
            'profiles' => [
                'default' => [
                    'author'       => 'urn:li:person:123',
                    'access_token' => 'li-token',
                    'version'      => '202602',
                ],
            ],
        ]);
    }
}
