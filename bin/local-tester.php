#!/usr/bin/env php
<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\SocializeManager;
use DrAliRagab\Socialize\Support\FluentShare;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Facade;

require \dirname(__DIR__) . '/vendor/autoload.php';

main($argv);

/**
 * @param array<int, string> $argv
 */
function main(array $argv): void
{
    try
    {
        $command = $argv[1] ?? null;

        if ($command === null || \in_array($command, ['-h', '--help', 'help'], true))
        {
            printHelp();

            return;
        }

        $optionOffset = 2;

        if (\is_string($command) && str_starts_with($command, '--'))
        {
            $command      = 'share';
            $optionOffset = 1;
        }

        $options = parseOptions(\array_slice($argv, $optionOffset));
        $rootDir = \dirname(__DIR__);
        $envFile = optionString($options, 'env-file', '.testing.env');
        $envPath = str_starts_with($envFile, '/') ? $envFile : $rootDir . '/' . $envFile;

        if (! file_exists($envPath))
        {
            throw new InvalidArgumentException(
                \sprintf('Env file not found: %s. Create it from .testing.env.example first.', $envPath),
            );
        }

        loadEnvFile($envPath);
        $socializeConfig = buildConfigFromEnv();

        bootstrapFacadeApplication($rootDir, $socializeConfig);

        $manager  = new SocializeManager(new Repository(['socialize' => $socializeConfig]));
        $provider = optionString($options, 'provider');
        $profile  = optionNullableString($options, 'profile');
        $fluent   = $manager->provider($provider, $profile);

        if ($command === 'share')
        {
            $fluent = applySharedOptions($fluent, $options);
            $fluent = applyProviderSpecificOptions($fluent, $provider, $options);
            $result = $fluent->share();

            output([
                'ok'       => true,
                'action'   => 'share',
                'provider' => $provider,
                'profile'  => $profile ?? 'default',
                'result'   => $result->toArray(),
            ]);

            return;
        }

        if ($command === 'delete')
        {
            $postId  = optionString($options, 'post-id');
            $deleted = $fluent->delete($postId);

            output([
                'ok'       => true,
                'action'   => 'delete',
                'provider' => $provider,
                'profile'  => $profile ?? 'default',
                'post_id'  => $postId,
                'deleted'  => $deleted,
            ]);

            return;
        }

        throw new InvalidArgumentException(\sprintf('Unknown command [%s]. Use share, delete, or --help.', $command));
    } catch (Throwable $throwable)
    {
        $payload = [
            'ok'      => false,
            'error'   => $throwable::class,
            'message' => $throwable->getMessage(),
        ];

        if ($throwable instanceof ApiException)
        {
            $payload['provider'] = $throwable->provider()->value;
            $payload['status']   = $throwable->status();
            $payload['response'] = $throwable->responseBody();
        }

        output($payload);

        exit(1);
    }
}

/**
 * @param list<string> $args
 * @return array<string, string>
 */
function parseOptions(array $args): array
{
    $options = [];

    foreach ($args as $arg)
    {
        if (! str_starts_with($arg, '--'))
        {
            throw new InvalidArgumentException(\sprintf('Invalid argument [%s]. Expected --key=value or --flag.', $arg));
        }

        $pair = mb_substr($arg, 2);

        if ($pair === '')
        {
            continue;
        }

        if (str_contains($pair, '='))
        {
            [$key, $value] = explode('=', $pair, 2);
        } else
        {
            $key   = $pair;
            $value = 'true';
        }

        if ($key === '')
        {
            continue;
        }

        $options[$key] = $value;
    }

    return $options;
}

function printHelp(): void
{
    $help = <<<'TXT'
Socialize Local Tester

Usage:
  php bin/local-tester.php share  --provider=facebook [options]
  php bin/local-tester.php delete --provider=facebook --post-id=POST_ID [options]
  php bin/local-tester.php --provider=facebook [share options]  # shorthand for share

Global options:
  --env-file=.testing.env       Optional path to env file (default: .testing.env)
  --profile=default             Optional provider profile name

Shared share options:
  --message="Text"
  --link="https://example.com"
  --link-title="Article title for LinkedIn link posts (optional)"
  --image-url="https://cdn.example.com/image.jpg"
  --video-url="https://cdn.example.com/video.mp4"
  --media-source="/path/to/file.jpg|https://example.com/file.jpg"
  --media-sources="/path/a.jpg,https://example.com/b.mp4"
  --media-type=image|video     Optional hint for media-source/media-sources
  --media-id="123"
  --media-ids="123,456"
  --metadata-json='{"source":"local-tester"}'
  --option-json='{"custom_key":"custom_value"}'
  --option-key="custom_key" --option-value="custom_value"
  --option-key="custom_key" --option-value-json='{"nested":true}'

Facebook options:
  --published=true|false
  --scheduled-at="2026-03-01 12:00:00"
  --targeting-json='{"geo_locations":{"countries":["US"]}}'

Instagram options:
  --reel
  --alt-text="Accessible text"
  --carousel-json='["https://cdn.example.com/1.jpg","https://cdn.example.com/2.jpg"]'

X (Twitter) options:
  --reply-to="123456789"
  --quote="123456789"
  --poll-options-json='["Yes","No"]'
  --poll-duration=60

LinkedIn options:
  --visibility=PUBLIC
  --distribution=MAIN_FEED
  --media-urn="urn:li:image:..."

Examples:
  php bin/local-tester.php share --provider=facebook --message="Hello" --link="https://example.com"
  php bin/local-tester.php share --provider=instagram --video-url="https://cdn.example.com/reel.mp4" --reel
  php bin/local-tester.php delete --provider=linkedin --post-id="urn:li:share:123"
TXT;

    fwrite(\STDOUT, $help . \PHP_EOL);
}

/**
 * @return array<string, mixed>
 */
function buildConfigFromEnv(): array
{
    return [
        'default_profile' => envValue('SOCIALIZE_DEFAULT_PROFILE', 'default'),
        'http'            => [
            'timeout'         => envInt('SOCIALIZE_HTTP_TIMEOUT', 120),
            'connect_timeout' => envInt('SOCIALIZE_HTTP_CONNECT_TIMEOUT', 30),
            'retries'         => envInt('SOCIALIZE_HTTP_RETRIES', 1),
            'retry_sleep_ms'  => envInt('SOCIALIZE_HTTP_RETRY_SLEEP_MS', 150),
        ],
        'temporary_media' => [
            'disk'       => envValue('SOCIALIZE_TEMP_MEDIA_DISK', 'public'),
            'directory'  => envValue('SOCIALIZE_TEMP_MEDIA_DIRECTORY', 'socialize-temp'),
            'visibility' => envValue('SOCIALIZE_TEMP_MEDIA_VISIBILITY', 'public'),
        ],
        'providers' => [
            'facebook' => [
                'base_url'      => envValue('SOCIALIZE_FACEBOOK_BASE_URL', 'https://graph.facebook.com'),
                'graph_version' => envValue('SOCIALIZE_FACEBOOK_GRAPH_VERSION', 'v25.0'),
                'profiles'      => [
                    'default' => [
                        'page_id'      => envValue('SOCIALIZE_FACEBOOK_PAGE_ID'),
                        'access_token' => envValue('SOCIALIZE_FACEBOOK_ACCESS_TOKEN'),
                    ],
                ],
            ],
            'instagram' => [
                'base_url'      => envValue('SOCIALIZE_INSTAGRAM_BASE_URL', 'https://graph.facebook.com'),
                'graph_version' => envValue('SOCIALIZE_INSTAGRAM_GRAPH_VERSION', 'v25.0'),
                'profiles'      => [
                    'default' => [
                        'ig_id'        => envValue('SOCIALIZE_INSTAGRAM_IG_ID'),
                        'access_token' => envValue('SOCIALIZE_INSTAGRAM_ACCESS_TOKEN'),
                    ],
                ],
            ],
            'twitter' => [
                'base_url' => envValue('SOCIALIZE_TWITTER_BASE_URL', 'https://api.x.com'),
                'profiles' => [
                    'default' => [
                        'bearer_token' => envValue('SOCIALIZE_TWITTER_BEARER_TOKEN'),
                    ],
                ],
            ],
            'linkedin' => [
                'base_url' => envValue('SOCIALIZE_LINKEDIN_BASE_URL', 'https://api.linkedin.com'),
                'profiles' => [
                    'default' => [
                        'author'       => envValue('SOCIALIZE_LINKEDIN_AUTHOR'),
                        'access_token' => envValue('SOCIALIZE_LINKEDIN_ACCESS_TOKEN'),
                        'version'      => envValue('SOCIALIZE_LINKEDIN_VERSION', '202602'),
                    ],
                ],
            ],
        ],
    ];
}

function envInt(string $key, int $default): int
{
    $value = envValue($key);

    if ($value === null || preg_match('/^-?\d+$/', $value) !== 1)
    {
        return $default;
    }

    return (int)$value;
}

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);

    if (! \is_string($value))
    {
        return $default;
    }

    $value = mb_trim($value);

    return $value === '' ? $default : $value;
}

function loadEnvFile(string $path): void
{
    $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

    if ($lines === false)
    {
        throw new InvalidArgumentException(\sprintf('Failed reading env file [%s].', $path));
    }

    foreach ($lines as $line)
    {
        $line = mb_trim($line);

        if ($line === '' || str_starts_with($line, '#'))
        {
            continue;
        }

        $line = str_starts_with($line, 'export ') ? mb_substr($line, 7) : $line;

        if (! str_contains($line, '='))
        {
            continue;
        }

        [$key, $raw] = explode('=', $line, 2);
        $key         = mb_trim($key);
        $value       = parseEnvValue($raw);

        if ($key === '')
        {
            continue;
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function parseEnvValue(string $raw): string
{
    $value = mb_trim($raw);

    if ($value === '')
    {
        return '';
    }

    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'")))
    {
        $value = mb_substr($value, 1, -1);
    }

    return str_replace(['\n', '\r', '\t'], [\PHP_EOL, "\r", "\t"], $value);
}

/**
 * @param array<string, string> $options
 */
function applySharedOptions(FluentShare $fluent, array $options): FluentShare
{
    if (\array_key_exists('message', $options))
    {
        $fluent = $fluent->message($options['message']);
    }

    if (\array_key_exists('link', $options))
    {
        $linkTitle = \array_key_exists('link-title', $options) ? optionString($options, 'link-title') : null;
        $fluent    = $fluent->link($options['link'], $linkTitle);
    } elseif (\array_key_exists('link-title', $options))
    {
        throw new InvalidArgumentException('link-title can only be used when link is provided.');
    }

    if (\array_key_exists('image-url', $options))
    {
        $fluent = $fluent->imageUrl($options['image-url']);
    }

    if (\array_key_exists('video-url', $options))
    {
        $fluent = $fluent->videoUrl($options['video-url']);
    }

    $mediaType = \array_key_exists('media-type', $options) ? optionString($options, 'media-type') : null;

    if (\array_key_exists('media-source', $options))
    {
        $fluent = $fluent->media(optionString($options, 'media-source'), $mediaType);
    }

    if (\array_key_exists('media-sources', $options))
    {
        $rawSources = explode(',', optionString($options, 'media-sources'));
        $sources    = array_values(array_filter(array_map('trim', $rawSources), static fn (string $source): bool => $source !== ''));

        foreach ($sources as $source)
        {
            $fluent = $fluent->media($source, $mediaType);
        }
    }

    if (\array_key_exists('media-id', $options))
    {
        $fluent = $fluent->mediaId(optionString($options, 'media-id'));
    }

    if (\array_key_exists('media-ids', $options))
    {
        $rawIds = explode(',', optionString($options, 'media-ids'));
        $ids    = array_values(array_filter(array_map('trim', $rawIds), static fn (string $id): bool => $id !== ''));

        if ($ids !== [])
        {
            $fluent = $fluent->mediaIds($ids);
        }
    }

    if (\array_key_exists('metadata-json', $options))
    {
        $metadata = optionJson($options, 'metadata-json');

        if (! \is_array($metadata))
        {
            throw new InvalidArgumentException('metadata-json must decode to an object.');
        }

        /** @var array<string, mixed> $metadata */
        $fluent = $fluent->metadata($metadata);
    }

    if (\array_key_exists('option-json', $options))
    {
        $customOptions = optionJson($options, 'option-json');

        if (! \is_array($customOptions))
        {
            throw new InvalidArgumentException('option-json must decode to an object.');
        }

        foreach ($customOptions as $key => $value)
        {
            if (! \is_string($key) || mb_trim($key) === '')
            {
                throw new InvalidArgumentException('option-json keys must be non-empty strings.');
            }

            $fluent = $fluent->option($key, $value);
        }
    }

    if (\array_key_exists('option-key', $options))
    {
        $optionKey = optionString($options, 'option-key');

        if (\array_key_exists('option-value-json', $options))
        {
            $fluent = $fluent->option($optionKey, optionJson($options, 'option-value-json'));
        } else
        {
            $fluent = $fluent->option($optionKey, optionString($options, 'option-value'));
        }
    }

    return $fluent;
}

/**
 * @param array<string, string> $options
 */
function applyProviderSpecificOptions(FluentShare $fluent, string $provider, array $options): FluentShare
{
    $provider = mb_strtolower(mb_trim($provider));

    if (\in_array($provider, ['facebook', 'fb'], true))
    {
        if (\array_key_exists('published', $options))
        {
            $fluent = $fluent->published(optionBool($options, 'published'));
        }

        if (\array_key_exists('scheduled-at', $options))
        {
            $fluent = $fluent->scheduledAt(optionIntOrString($options, 'scheduled-at'));
        }

        if (\array_key_exists('targeting-json', $options))
        {
            $targeting = optionJson($options, 'targeting-json');

            if (! \is_array($targeting))
            {
                throw new InvalidArgumentException('targeting-json must decode to an object.');
            }

            /** @var array<string, mixed> $targeting */
            $fluent = $fluent->targeting($targeting);
        }
    }

    if (\in_array($provider, ['instagram', 'ig'], true))
    {
        if (optionFlag($options, 'reel'))
        {
            $fluent = $fluent->reel();
        }

        if (\array_key_exists('alt-text', $options))
        {
            $fluent = $fluent->altText(optionString($options, 'alt-text'));
        }

        if (\array_key_exists('carousel-json', $options))
        {
            $carousel = optionJson($options, 'carousel-json');

            if (! \is_array($carousel))
            {
                throw new InvalidArgumentException('carousel-json must decode to an array.');
            }

            $items = array_values(array_filter(array_map(static fn (mixed $item): string => \is_string($item) ? mb_trim($item) : '', $carousel), static fn (string $item): bool => $item !== ''));

            $fluent = $fluent->carousel($items);
        }
    }

    if (\in_array($provider, ['twitter', 'x'], true))
    {
        if (\array_key_exists('reply-to', $options))
        {
            $fluent = $fluent->replyTo(optionString($options, 'reply-to'));
        }

        if (\array_key_exists('quote', $options))
        {
            $fluent = $fluent->quote(optionString($options, 'quote'));
        }

        if (\array_key_exists('poll-options-json', $options))
        {
            $pollOptions = optionJson($options, 'poll-options-json');

            if (! \is_array($pollOptions))
            {
                throw new InvalidArgumentException('poll-options-json must decode to an array.');
            }

            $cleanOptions = array_values(array_filter(array_map(static fn (mixed $item): string => \is_string($item) ? mb_trim($item) : '', $pollOptions), static fn (string $item): bool => $item !== ''));

            if (\count($cleanOptions) < 2)
            {
                throw new InvalidArgumentException('poll-options-json must contain at least 2 options.');
            }

            $duration = optionInt($options, 'poll-duration', 30);
            $fluent   = $fluent->poll($cleanOptions, $duration);
        }
    }

    if (\in_array($provider, ['linkedin', 'li'], true))
    {
        if (\array_key_exists('visibility', $options))
        {
            $fluent = $fluent->visibility(optionString($options, 'visibility'));
        }

        if (\array_key_exists('distribution', $options))
        {
            $fluent = $fluent->distribution(optionString($options, 'distribution'));
        }

        if (\array_key_exists('media-urn', $options))
        {
            $fluent = $fluent->mediaUrn(optionString($options, 'media-urn'));
        }
    }

    return $fluent;
}

/**
 * @param array<string, string> $options
 */
function optionString(array $options, string $key, ?string $default = null): string
{
    if (! \array_key_exists($key, $options) && $default === null)
    {
        throw new InvalidArgumentException(\sprintf('Missing required option [%s].', $key));
    }

    $value = $options[$key] ?? $default;

    if (! \is_string($value))
    {
        throw new InvalidArgumentException(\sprintf('Option [%s] must be a string.', $key));
    }

    $value = mb_trim($value);

    if ($value === '')
    {
        throw new InvalidArgumentException(\sprintf('Option [%s] is required and cannot be empty.', $key));
    }

    return $value;
}

/**
 * @param array<string, string> $options
 */
function optionNullableString(array $options, string $key): ?string
{
    if (! \array_key_exists($key, $options))
    {
        return null;
    }

    $value = mb_trim($options[$key]);

    return $value === '' ? null : $value;
}

/**
 * @param array<string, string> $options
 */
function optionBool(array $options, string $key): bool
{
    $value = mb_strtolower(optionString($options, $key));

    if (\in_array($value, ['1', 'true', 'yes', 'on'], true))
    {
        return true;
    }

    if (\in_array($value, ['0', 'false', 'no', 'off'], true))
    {
        return false;
    }

    throw new InvalidArgumentException(\sprintf('Option [%s] must be a boolean-like value.', $key));
}

/**
 * @param array<string, string> $options
 */
function optionFlag(array $options, string $key): bool
{
    return \array_key_exists($key, $options) && mb_strtolower(mb_trim($options[$key])) !== 'false';
}

/**
 * @param array<string, string> $options
 */
function optionInt(array $options, string $key, int $default): int
{
    if (! \array_key_exists($key, $options))
    {
        return $default;
    }

    $value = optionString($options, $key);

    if (preg_match('/^-?\d+$/', $value) !== 1)
    {
        throw new InvalidArgumentException(\sprintf('Option [%s] must be an integer.', $key));
    }

    return (int)$value;
}

/**
 * @param array<string, string> $options
 */
function optionIntOrString(array $options, string $key): int|string
{
    $value = optionString($options, $key);

    if (preg_match('/^-?\d+$/', $value) === 1)
    {
        return (int)$value;
    }

    return $value;
}

/**
 * @param array<string, string> $options
 */
function optionJson(array $options, string $key): mixed
{
    return json_decode(optionString($options, $key), true, 512, \JSON_THROW_ON_ERROR);
}

/**
 * @param array<string, mixed> $data
 */
function output(array $data): void
{
    $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    fwrite(\STDOUT, $encoded . \PHP_EOL);
}

/**
 * @param array<string, mixed> $socializeConfig
 */
function bootstrapFacadeApplication(string $rootDir, array $socializeConfig): void
{
    $container = new Container();
    $http      = new HttpFactory();
    $files     = new Filesystem();
    $appUrl    = envValue('APP_URL', 'http://localhost');

    $storagePath    = $rootDir . '/storage';
    $publicDiskRoot = $storagePath . '/app/public';
    $localDiskRoot  = $storagePath . '/app/private';

    if (! is_dir($publicDiskRoot))
    {
        mkdir($publicDiskRoot, 0755, true);
    }

    if (! is_dir($localDiskRoot))
    {
        mkdir($localDiskRoot, 0755, true);
    }

    $config = new Repository([
        'app' => [
            'url' => $appUrl,
        ],
        'socialize'   => $socializeConfig,
        'filesystems' => [
            'default' => 'public',
            'disks'   => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $localDiskRoot,
                    'throw'  => false,
                ],
                'public' => [
                    'driver'     => 'local',
                    'root'       => $publicDiskRoot,
                    'url'        => mb_rtrim($appUrl, '/') . '/storage',
                    'visibility' => 'public',
                    'throw'      => false,
                ],
            ],
        ],
    ]);

    $filesystemManager = new FilesystemManager($container);

    Container::setInstance($container);
    $container->instance(Container::class, $container);
    $container->instance('app', $container);
    $container->instance('path.base', $rootDir);
    $container->instance('path.storage', $storagePath);
    $container->instance('path.public', $rootDir . '/public');
    $container->instance(Repository::class, $config);
    $container->instance('config', $config);
    $container->instance(HttpFactory::class, $http);
    $container->instance('http', $http);
    $container->instance(Filesystem::class, $files);
    $container->instance('files', $files);
    $container->instance(FilesystemManager::class, $filesystemManager);
    $container->instance('filesystem', $filesystemManager);

    Facade::setFacadeApplication($container);
}
