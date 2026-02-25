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

Use the same fluent media call across providers:

```php
Socialize::provider('facebook')->media('/absolute/path/banner.jpg', 'image')->share();
Socialize::provider('twitter')->media('/absolute/path/clip.mp4', 'video')->share();
Socialize::provider('linkedin')->media('https://picsum.photos/1200/800', 'image')->share();
```

## Shared Fluent Methods
- `message(?string $message)`
- `link(?string $url, ?string $articleTitle = null)` (`$articleTitle` is used for LinkedIn link posts)
- `imageUrl(?string $url)`
- `videoUrl(?string $url)`
- `media(string $source, ?string $mediaType = null)` (`$source` can be URL or local file path)
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
- `SOCIALIZE_HTTP_TIMEOUT` (optional, default: `120`)
- `SOCIALIZE_HTTP_CONNECT_TIMEOUT` (optional, default: `30`)
- `SOCIALIZE_HTTP_RETRIES` (optional, default: `1`)
  - This maps directly to Laravel `Http::retry($times, ...)`, so the value is total attempts. `1` means no retry after the first attempt.
- `SOCIALIZE_HTTP_RETRY_SLEEP_MS` (optional)
- `SOCIALIZE_TEMP_MEDIA_DISK` (optional, default: `public`)
- `SOCIALIZE_TEMP_MEDIA_DIRECTORY` (optional, default: `socialize-temp`)
- `SOCIALIZE_TEMP_MEDIA_VISIBILITY` (optional, default: `public`)

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
- `SOCIALIZE_INSTAGRAM_PUBLISH_RETRY_ATTEMPTS` (optional, default: `20`)
- `SOCIALIZE_INSTAGRAM_PUBLISH_RETRY_SLEEP_SECONDS` (optional, default: `3`)

X (Twitter):
- `SOCIALIZE_TWITTER_BEARER_TOKEN` (required)
- `SOCIALIZE_TWITTER_BASE_URL` (optional)
- `SOCIALIZE_TWITTER_MEDIA_PROCESSING_POLL_ATTEMPTS` (optional, default: `15`)
  - Must be an OAuth 2.0 **User Context** token (app-only bearer tokens are rejected by X for post/media endpoints).
  - Required OAuth scopes for full Socialize X support: `tweet.read tweet.write users.read media.write` (plus `offline.access` if you need refresh tokens).

LinkedIn:
- `SOCIALIZE_LINKEDIN_AUTHOR` (required)
- `SOCIALIZE_LINKEDIN_ACCESS_TOKEN` (required)
- `SOCIALIZE_LINKEDIN_VERSION` (optional, default: `202602`, format: `YYYYMM`)
- `SOCIALIZE_LINKEDIN_BASE_URL` (optional)

## Platform Notes
- Facebook/Instagram use Graph API versioning from config.
- Instagram content publishing is multi-step (container then publish).
- Instagram publish failure handling polls `GET /{container-id}?fields=status_code,status` and retries publish for "not ready" states.
- Instagram video publishing defaults to `REELS` media type (Meta deprecates `VIDEO` feed publishing); passing `VIDEO` is normalized to `REELS` for compatibility.
- Facebook/Instagram accept URL media. When you pass a local file path via `media(...)`, Socialize creates a temporary public URL, uses it for the request, then deletes the temporary file after success/failure.
- X and LinkedIn auto-upload media from `media(...)`, `imageUrl(...)`, and `videoUrl(...)` sources and resolve the required media IDs / URNs internally.
- X image uploads use `POST /2/media/upload` (base64 payload), while X video uploads use v2 chunked commands on `/2/media/upload` (`INIT`, `APPEND`, `FINALIZE`, `STATUS`).
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

## Local Tester

You can run real end-to-end provider calls locally without creating a full Laravel app.

1. Create `.testing.env` from `.testing.env.example` and fill credentials.
2. Run the CLI tester:

```bash
php bin/local-tester.php --help
```

Full command reference:
- [`bin/LOCAL_TESTER_COMMANDS.md`](bin/LOCAL_TESTER_COMMANDS.md)

Share examples:

```bash
php bin/local-tester.php share --provider=facebook --message="Hello" --link="https://example.com"
php bin/local-tester.php share --provider=instagram --video-url="https://cdn.example.com/reel.mp4" --reel
php bin/local-tester.php share --provider=twitter --message="Launch" --link="https://example.com"
php bin/local-tester.php share --provider=linkedin --message="Update" --link="https://example.com"
```

Delete example:

```bash
php bin/local-tester.php delete --provider=linkedin --post-id="urn:li:share:123"
```

## License
MIT
