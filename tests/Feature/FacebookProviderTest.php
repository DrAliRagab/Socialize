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
        ->videoTitle('Separate video title')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-video');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos')
        && ($request->data()['title'] ?? null)       === 'Separate video title'
        && ($request->data()['description'] ?? null) === 'Video post');
});

it('publishes a facebook reel through the dedicated reels workflow', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/12345/video_reels' => Http::sequence()
            ->push([
                'video_id'   => 'fb-reel-video',
                'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/fb-reel-video',
            ], 200)
            ->push(['success' => true], 200),
        'https://rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Reel caption')
        ->videoUrl('https://cdn.example.com/reel.mp4')
        ->videoTitle('Separate Reel title')
        ->reel()
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-reel-video')
        ->and($shareResult->url())->toBe('https://www.facebook.com/reel/fb-reel-video')
    ;

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/12345/video_reels'
        && ($request->data()['upload_phase'] ?? null)               === 'START'
        && ($request->data()['access_token'] ?? null)               === 'fb-token');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rupload.facebook.com/video-upload/v25.0/fb-reel-video'
        && $request->method()                                       === 'POST'
        && $request->hasHeader('Authorization', 'OAuth fb-token')
        && $request->hasHeader('file_url', 'https://cdn.example.com/reel.mp4')
        && $request->body() === '');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/12345/video_reels'
        && ($request->data()['upload_phase'] ?? null)               === 'FINISH'
        && ($request->data()['video_id'] ?? null)                   === 'fb-reel-video'
        && ($request->data()['video_state'] ?? null)                === 'PUBLISHED'
        && ($request->data()['title'] ?? null)                      === 'Separate Reel title'
        && ($request->data()['description'] ?? null)                === 'Reel caption');
});

it('uploads a local facebook reel directly without creating a temporary public URL', function (): void {
    Storage::fake('public');

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-fb-reel-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for facebook local reel test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, 'local-reel-bytes');

    Http::fake([
        'https://graph.facebook.com/v25.0/12345/video_reels' => Http::sequence()
            ->push([
                'video_id'   => 'fb-local-reel',
                'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/fb-local-reel',
            ], 200)
            ->push(['success' => true], 200),
        'https://rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    try
    {
        $shareResult = Socialize::facebook()
            ->media($videoPath, 'video')
            ->reel()
            ->share()
        ;

        expect($shareResult->id())->toBe('fb-local-reel');
    } finally
    {
        @unlink($videoPath);
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rupload.facebook.com/video-upload/v25.0/fb-local-reel'
        && $request->hasHeader('Authorization', 'OAuth fb-token')
        && $request->hasHeader('offset', '0')
        && $request->hasHeader('file_size', (string)\strlen('local-reel-bytes'))
        && $request->hasHeader('Content-Type', 'application/octet-stream')
        && ! $request->hasHeader('file_url'));

    expect(Storage::disk('public')->allFiles('socialize-temp'))->toBe([]);
});

it('throws when facebook rejects a local reel upload', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-fb-reel-failed-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for facebook failed reel upload test.');
    }

    file_put_contents($tempFile, 'failed-reel-bytes');

    Http::fake([
        'https://graph.facebook.com/v25.0/12345/video_reels' => Http::response([
            'video_id'   => 'fb-failed-upload',
            'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/fb-failed-upload',
        ], 200),
        'https://rupload.facebook.com/*' => Http::response(['success' => false], 200),
    ]);

    try
    {
        expect(fn () => Socialize::facebook()
            ->media($tempFile, 'video')
            ->reel()
            ->share())
            ->toThrow(ApiException::class, 'Facebook API did not upload the reel video.')
        ;
    } finally
    {
        @unlink($tempFile);
    }

    Http::assertNotSent(fn (Request $request): bool => ($request->data()['upload_phase'] ?? null) === 'FINISH');
});

it('requires a video when publishing a facebook reel', function (): void {
    Http::fake();

    Socialize::facebook()
        ->message('Missing video')
        ->reel()
        ->share()
    ;
})->throws(InvalidSharePayloadException::class, 'requires a video');

it('schedules a targeted facebook reel and falls back to the documented upload endpoint', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/12345/video_reels' => Http::sequence()
            ->push(['video_id' => 123456], 200)
            ->push(['success' => true], 200),
        'https://rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->videoUrl('https://cdn.example.com/scheduled-reel.mp4')
        ->reel()
        ->option('scheduled_at', '2026-09-01 12:00:00')
        ->targeting(['geo_locations' => ['countries' => ['SA']]])
        ->share()
    ;

    expect($shareResult->id())->toBe('123456');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://rupload.facebook.com/video-upload/v25.0/123456'
        && $request->hasHeader('file_url', 'https://cdn.example.com/scheduled-reel.mp4'));

    Http::assertSent(fn (Request $request): bool => ($request->data()['upload_phase'] ?? null) === 'FINISH'
        && ($request->data()['video_state'] ?? null)                                           === 'SCHEDULED'
        && \is_int($request->data()['scheduled_publish_time'] ?? null)
        && ($request->data()['targeting']['geo_locations']['countries'] ?? null) === ['SA']
        && ! \array_key_exists('description', $request->data()));
});

it('publishes a facebook reel as a draft when published is false', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/12345/video_reels' => Http::sequence()
            ->push([
                'video_id'   => 'fb-draft-reel',
                'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/fb-draft-reel',
            ], 200)
            ->push(['success' => true], 200),
        'https://rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    Socialize::facebook()
        ->videoUrl('https://cdn.example.com/draft-reel.mp4')
        ->reel()
        ->published(false)
        ->share()
    ;

    Http::assertSent(fn (Request $request): bool => ($request->data()['upload_phase'] ?? null) === 'FINISH'
        && ($request->data()['video_state'] ?? null)                                           === 'DRAFT');
});

it('throws when facebook reel creation does not return a video id', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/missing'], 200),
    ]);

    Socialize::facebook()
        ->videoUrl('https://cdn.example.com/reel.mp4')
        ->reel()
        ->share()
    ;
})->throws(ApiException::class, 'did not return a reel video id');

it('throws when facebook reports a logical reel publishing failure', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/12345/video_reels' => Http::sequence()
            ->push([
                'video_id'   => 'fb-failed-reel',
                'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/fb-failed-reel',
            ], 200)
            ->push(['success' => false], 200),
        'https://rupload.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    Socialize::facebook()
        ->videoUrl('https://cdn.example.com/reel.mp4')
        ->reel()
        ->share()
    ;
})->throws(ApiException::class, 'did not publish the reel');

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
