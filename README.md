# Socialize

Unified social media publishing for Laravel applications.

`Socialize` provides one fluent API for sharing to:
- Facebook
- Instagram
- X (Twitter)
- LinkedIn

## Requirements
- PHP 8.4+
- Laravel 11 or 12

## Installation

```bash
composer require draliragab/socialize
```

Publish config:

```bash
php artisan vendor:publish --tag="socialize-config"
```

## Quick Start

```php
use DrAliRagab\Socialize\Facades\Socialize;

$result = Socialize::provider('facebook')
    ->message('New release is live')
    ->link('https://example.com/release')
    ->share();

echo $result->id();
```

Switch provider with the same shared methods:

```php
foreach (['facebook', 'twitter', 'linkedin'] as $provider) {
    Socialize::provider($provider)
        ->message('Hello from Socialize')
        ->link('https://example.com')
        ->share();
}
```

## Shared Fluent Methods
- `message(?string $message)`
- `link(?string $url)`
- `imageUrl(?string $url)`
- `videoUrl(?string $url)`
- `mediaId(string $id)`
- `mediaIds(array $ids)`
- `metadata(array $metadata)`
- `option(string $key, mixed $value)`
- `share(): ShareResult`
- `delete(string $postId): bool`

## Provider-Specific Methods

### Facebook
- `published(bool $published = true)`
- `scheduledAt(string|int|DateTimeInterface $dateTime)`
- `targeting(array $targeting)`

### Instagram
- `carousel(array $imageUrls)`
- `altText(string $text)`
- `reel()`

### X (Twitter)
- `replyTo(string $postId)`
- `quote(string $postId)`
- `poll(array $options, int $durationMinutes)`

### LinkedIn
- `visibility(string $visibility)`
- `distribution(string $distribution)`
- `mediaUrn(string $mediaUrn)`

## Model Trait

Use `DrAliRagab\Socialize\Concerns\CanShareSocially`:

```php
use DrAliRagab\Socialize\Concerns\CanShareSocially;

class Post extends Model
{
    use CanShareSocially;
}

$post->shareToFacebook();
$post->shareTo('linkedin');
```

The trait maps columns from `socialize.model_columns`.

## Configuration

`config/socialize.php` supports named profiles per provider.

Minimal example:

```php
'providers' => [
    'facebook' => [
        'base_url' => 'https://graph.facebook.com',
        'graph_version' => 'v25.0',
        'profiles' => [
            'default' => [
                'page_id' => env('SOCIALIZE_FACEBOOK_PAGE_ID'),
                'access_token' => env('SOCIALIZE_FACEBOOK_ACCESS_TOKEN'),
            ],
        ],
    ],
],
```

### Required `.env` Variables

General:
- `SOCIALIZE_DEFAULT_PROFILE` (optional, default: `default`)
- `SOCIALIZE_HTTP_TIMEOUT` (optional)
- `SOCIALIZE_HTTP_CONNECT_TIMEOUT` (optional)
- `SOCIALIZE_HTTP_RETRIES` (optional)
- `SOCIALIZE_HTTP_RETRY_SLEEP_MS` (optional)

Facebook:
- `SOCIALIZE_FACEBOOK_PAGE_ID` (required)
- `SOCIALIZE_FACEBOOK_ACCESS_TOKEN` (required)
- `SOCIALIZE_FACEBOOK_GRAPH_VERSION` (optional, default: `v25.0`)
- `SOCIALIZE_FACEBOOK_BASE_URL` (optional)

Instagram:
- `SOCIALIZE_INSTAGRAM_IG_ID` (required)
- `SOCIALIZE_INSTAGRAM_ACCESS_TOKEN` (required)
- `SOCIALIZE_INSTAGRAM_GRAPH_VERSION` (optional, default: `v25.0`)
- `SOCIALIZE_INSTAGRAM_BASE_URL` (optional)

X (Twitter):
- `SOCIALIZE_TWITTER_BEARER_TOKEN` (required)
- `SOCIALIZE_TWITTER_BASE_URL` (optional)

LinkedIn:
- `SOCIALIZE_LINKEDIN_AUTHOR` (required)
- `SOCIALIZE_LINKEDIN_ACCESS_TOKEN` (required)
- `SOCIALIZE_LINKEDIN_VERSION` (optional, default: `202602`, format: `YYYYMM`)
- `SOCIALIZE_LINKEDIN_BASE_URL` (optional)

## Platform Notes
- Facebook/Instagram use Graph API versioning from config.
- Instagram content publishing is multi-step (container then publish).
- Instagram publish failure troubleshooting uses `GET /{container-id}?fields=status_code,status`.
- X media uploads should be done before publish; pass media IDs to `mediaId/mediaIds`.
- LinkedIn requires `Linkedin-Version` and `X-Restli-Protocol-Version` headers.

## Testing

```bash
composer test
composer test-coverage
```

## Static Analysis

```bash
composer analyse
```

## Formatting

```bash
composer format
```

## Rector

Run baseline/default Rector config:

```bash
composer rector:default
```

Run stricter Rector config:

```bash
composer rector
```

## License
MIT
