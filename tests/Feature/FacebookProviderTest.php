<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Exceptions\UnsupportedFeatureException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('shares a facebook feed post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-1'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Release note')
        ->link('https://example.com/release')
        ->published(true)
        ->targeting(['geo_locations' => ['countries' => ['US']]])
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-1')
        ->and($shareResult->provider()->value)->toBe('facebook')
        ->and($shareResult->url())->toBe('https://www.facebook.com/fb-1')
    ;

    Http::assertSent(
        fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/12345/feed'
        && $request->method()                          === 'POST'
        && ($request->data()['link'] ?? null)          === 'https://example.com/release'
    );
});

it('parses facebook scheduled_at string option in provider payload', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-scheduled-string'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Scheduled via raw option')
        ->option('scheduled_at', '2026-03-01 12:00:00')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-scheduled-string');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/12345/feed'
        && \array_key_exists('scheduled_publish_time', $request->data()));
});

it('creates a facebook comment on an existing post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-comment-1'], 200),
    ]);

    $commentResult = Socialize::facebook()->commentOn('fb-post-1', 'Nice update!');

    expect($commentResult->id())->toBe('fb-comment-1')
        ->and($commentResult->postId())->toBe('fb-post-1')
        ->and($commentResult->provider()->value)->toBe('facebook')
    ;

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/fb-post-1/comments'
        && ($request->data()['message'] ?? null)                    === 'Nice update!');
});

it('throws when facebook comment response is missing id', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response([], 200),
    ]);

    Socialize::facebook()->commentOn('fb-post-1', 'Nice update!');
})->throws(ApiException::class, 'Facebook API did not return a comment id');

it('shares a facebook photo post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['post_id' => 'fb-photo'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Photo post')
        ->imageUrl('https://cdn.example.com/pic.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-photo');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/photos'));
});

it('shares a facebook photo post without caption parts', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['post_id' => 'fb-photo-no-caption'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->imageUrl('https://cdn.example.com/pic.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-photo-no-caption');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_contains($request->url(), '/photos')
            && \array_key_exists('caption', $data)
            && $data['caption'] === null;
    });
});

it('shares a facebook video post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-video'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Video post')
        ->videoUrl('https://cdn.example.com/video.mp4')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-video');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos'));
});

it('applies scheduling and targeting options to facebook photo and video shares', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::sequence()
            ->push(['post_id' => 'fb-photo-scheduled'], 200)
            ->push(['id' => 'fb-video-scheduled'], 200),
    ]);

    Socialize::facebook()
        ->imageUrl('https://cdn.example.com/photo.jpg')
        ->scheduledAt(1_772_000_000)
        ->targeting(['geo_locations' => ['countries' => ['US']]])
        ->share()
    ;

    Socialize::facebook()
        ->videoUrl('https://cdn.example.com/video.mp4')
        ->scheduledAt(1_772_000_100)
        ->targeting(['geo_locations' => ['countries' => ['CA']]])
        ->share()
    ;

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/photos')
        && ($request->data()['published'] ?? null)                               === false
        && ($request->data()['scheduled_publish_time'] ?? null)                  === 1_772_000_000
        && ($request->data()['targeting']['geo_locations']['countries'] ?? null) === ['US']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos')
        && ($request->data()['published'] ?? null)                               === false
        && ($request->data()['scheduled_publish_time'] ?? null)                  === 1_772_000_100
        && ($request->data()['targeting']['geo_locations']['countries'] ?? null) === ['CA']);
});

it('returns false when facebook delete response indicates logical failure', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => false], 200),
    ]);

    expect(Socialize::facebook()->delete('123_456'))->toBeFalse();
});

it('shares facebook media from local image file through temporary URL and cleans it up', function (): void {
    Storage::fake('public');

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-fb-image-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for facebook local image test.');
    }

    $imagePath = $tempFile . '.jpg';
    rename($tempFile, $imagePath);
    file_put_contents($imagePath, 'image-bytes');

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['post_id' => 'fb-local-photo'], 200),
    ]);

    try
    {
        $shareResult = Socialize::facebook()
            ->media($imagePath, 'image')
            ->share()
        ;

        expect($shareResult->id())->toBe('fb-local-photo');
    } finally
    {
        @unlink($imagePath);
    }

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/photos')
        && str_contains((string)($request->data()['url'] ?? ''), '/storage/socialize-temp/'));

    expect(Storage::disk('public')->allFiles('socialize-temp'))->toBe([]);
});

it('shares facebook media from local video file through temporary URL and cleans it up', function (): void {
    Storage::fake('public');

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-fb-video-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for facebook local video test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, 'video-bytes');

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-local-video'], 200),
    ]);

    try
    {
        $shareResult = Socialize::facebook()
            ->media($videoPath, 'video')
            ->share()
        ;

        expect($shareResult->id())->toBe('fb-local-video');
    } finally
    {
        @unlink($videoPath);
    }

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos')
        && str_contains((string)($request->data()['file_url'] ?? ''), '/storage/socialize-temp/'));

    expect(Storage::disk('public')->allFiles('socialize-temp'))->toBe([]);
});

it('deletes a facebook post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    expect(Socialize::facebook()->delete('123_456'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->hasHeader('Authorization', 'Bearer fb-token')
        && ! \array_key_exists('access_token', $request->data()));
});

it('throws for empty facebook post id on delete', function (): void {
    Http::fake();

    Socialize::facebook()->delete('   ');
})->throws(InvalidSharePayloadException::class, 'Facebook post id cannot be empty');

it('throws for invalid facebook link url', function (): void {
    Http::fake();

    Socialize::facebook()
        ->message('Invalid link')
        ->link('not-a-url')
        ->share()
    ;
})->throws(InvalidSharePayloadException::class, 'must be a valid URL');

it('throws for missing facebook content', function (): void {
    Http::fake();

    Socialize::facebook()->share();
})->throws(InvalidSharePayloadException::class, 'requires at least one');

it('throws for unsupported facebook media ids payload field', function (): void {
    Http::fake();

    Socialize::facebook()
        ->message('Unsupported field')
        ->mediaId('123')
        ->share()
    ;
})->throws(UnsupportedFeatureException::class, 'mediaIds');

it('throws when facebook credentials are missing in selected profile', function (): void {
    Http::fake();

    Socialize::facebook('missing-token')
        ->message('Hello')
        ->share()
    ;
})->throws(InvalidConfigException::class, 'Missing required credential [access_token]');

it('throws api exception on facebook failure', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token']], 401),
    ]);

    Socialize::facebook()->message('Hello')->share();
})->throws(ApiException::class, 'Facebook API request failed with status 401');

it('throws for facebook invalid response body missing id', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['ok' => true], 200),
    ]);

    Socialize::facebook()->message('Hello')->share();
})->throws(ApiException::class, 'did not return a post id');

it('falls back to default facebook base url when base_url config is invalid', function (): void {
    config()->set('socialize.providers.facebook.base_url', ['invalid']);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-default-base'], 200),
    ]);

    $shareResult = Socialize::facebook()->message('Fallback base URL')->share();

    expect($shareResult->id())->toBe('fb-default-base');
});
