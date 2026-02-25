<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('shares instagram image content', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-post-1'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('New image')
        ->imageUrl('https://cdn.example.com/ig.jpg')
        ->altText('Product hero image')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-post-1')
        ->and($shareResult->raw()['container_id'])->toBe('container-1')
    ;
});

it('shares instagram reels video', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-video'], 200),
        'https://graph.facebook.com/v25.0/container-video*'    => Http::response(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-video-1'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Reel')
        ->videoUrl('https://cdn.example.com/reel.mp4')
        ->reel()
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/media')
        && ! str_contains($request->url(), '/media_publish')
        && ($request->data()['media_type'] ?? null) === 'REELS');
});

it('sends instagram reels media_type only on container creation and not on publish call', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-video'], 200),
        'https://graph.facebook.com/v25.0/container-video*'    => Http::response(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-video-2'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Reel request shape')
        ->videoUrl('https://cdn.example.com/reel-2.mp4')
        ->reel()
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-2');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/98765/media'
        && ($request->data()['media_type'] ?? null)                 === 'REELS');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://graph.facebook.com/v25.0/98765/media_publish')
        {
            return false;
        }

        return ! \array_key_exists('media_type', $request->data())
            && ($request->data()['creation_id'] ?? null) === 'container-video';
    });

    Http::assertSentCount(3);
});

it('uses default instagram REELS media_type for video containers when reel is not requested', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'              => Http::response(['id' => 'container-video-default'], 200),
        'https://graph.facebook.com/v25.0/container-video-default*' => Http::response(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish'      => Http::response(['id' => 'ig-video-default'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Default video type')
        ->videoUrl('https://cdn.example.com/default.mp4')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-default');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/98765/media'
        && ($request->data()['media_type'] ?? null)                 === 'REELS');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/98765/media_publish');
});

it('maps explicit instagram VIDEO media_type option to REELS for compatibility', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'               => Http::response(['id' => 'container-video-explicit'], 200),
        'https://graph.facebook.com/v25.0/container-video-explicit*' => Http::response(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish'       => Http::response(['id' => 'ig-video-explicit'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Explicit VIDEO')
        ->videoUrl('https://cdn.example.com/explicit-video.mp4')
        ->option('media_type', 'VIDEO')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-explicit');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/98765/media'
        && ($request->data()['media_type'] ?? null)                 === 'REELS');
});

it('waits for instagram video container readiness before publish', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'            => Http::response(['id' => 'container-video-ready'], 200),
        'https://graph.facebook.com/v25.0/container-video-ready*' => Http::sequence()
            ->push(['status_code' => 'IN_PROGRESS', 'status' => 'IN_PROGRESS'], 200)
            ->push(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-video-ready'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->videoUrl('https://cdn.example.com/video-ready.mp4')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-ready');
    Http::assertSentCount(4);
});

it('continues instagram publish when container status reports error and relies on publish result', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'            => Http::response(['id' => 'container-video-error'], 200),
        'https://graph.facebook.com/v25.0/container-video-error*' => Http::response([
            'status_code' => 'ERROR',
            'status'      => 'ERROR',
        ], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-video-status-error'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->videoUrl('https://cdn.example.com/video-error.mp4')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-status-error');
});

it('shares instagram carousel content', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'parent-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-carousel-1'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Carousel')
        ->carousel([
            'https://cdn.example.com/1.jpg',
            'https://cdn.example.com/2.jpg',
        ])
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-carousel-1');

    Http::assertSentCount(4);
});

it('shares instagram image from local file through temporary URL and cleans it up', function (): void {
    Storage::fake('public');

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-ig-image-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for instagram local image test.');
    }

    $imagePath = $tempFile . '.jpg';
    rename($tempFile, $imagePath);
    file_put_contents($imagePath, 'image-bytes');

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-local-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-local-1'], 200),
    ]);

    try
    {
        $shareResult = Socialize::instagram()
            ->media($imagePath, 'image')
            ->share()
        ;

        expect($shareResult->id())->toBe('ig-local-1');
    } finally
    {
        @unlink($imagePath);
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/98765/media'
        && str_contains((string)($request->data()['image_url'] ?? ''), '/storage/socialize-temp/'));

    expect(Storage::disk('public')->allFiles('socialize-temp'))->toBe([]);
});

it('shares instagram carousel with local files through temporary URLs and cleans them up', function (): void {
    Storage::fake('public');

    $tempOne = tempnam(sys_get_temp_dir(), 'socialize-ig-carousel-');
    $tempTwo = tempnam(sys_get_temp_dir(), 'socialize-ig-carousel-');

    if (! \is_string($tempOne) || ! \is_string($tempTwo))
    {
        throw new RuntimeException('Failed to create temporary files for instagram local carousel test.');
    }

    $imageOne = $tempOne . '.jpg';
    $imageTwo = $tempTwo . '.jpg';
    rename($tempOne, $imageOne);
    rename($tempTwo, $imageTwo);
    file_put_contents($imageOne, 'image-one');
    file_put_contents($imageTwo, 'image-two');

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::sequence()
            ->push(['id' => 'child-local-1'], 200)
            ->push(['id' => 'child-local-2'], 200)
            ->push(['id' => 'parent-local-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-carousel-local-1'], 200),
    ]);

    try
    {
        $shareResult = Socialize::instagram()
            ->message('Carousel local')
            ->carousel([$imageOne, $imageTwo])
            ->share()
        ;

        expect($shareResult->id())->toBe('ig-carousel-local-1');
    } finally
    {
        @unlink($imageOne);
        @unlink($imageTwo);
    }

    Http::assertSentCount(4);
    expect(Storage::disk('public')->allFiles('socialize-temp'))->toBe([]);
});

it('shares instagram video from fluent media source', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'            => Http::response(['id' => 'container-video-media'], 200),
        'https://graph.facebook.com/v25.0/container-video-media*' => Http::response(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish'    => Http::response(['id' => 'ig-video-media'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Video from media')
        ->media('https://cdn.example.com/from-media.mp4', 'video')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-media');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/98765/media'
        && ($request->data()['video_url'] ?? null)                  === 'https://cdn.example.com/from-media.mp4');
});

it('shares instagram video from local path in videoUrl through temporary URL', function (): void {
    Storage::fake('public');

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-ig-video-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for instagram local video test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, 'video-bytes');

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'            => Http::response(['id' => 'container-local-video'], 200),
        'https://graph.facebook.com/v25.0/container-local-video*' => Http::response(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish'    => Http::response(['id' => 'ig-local-video'], 200),
    ]);

    try
    {
        $shareResult = Socialize::instagram()
            ->videoUrl($videoPath)
            ->share()
        ;

        expect($shareResult->id())->toBe('ig-local-video');
    } finally
    {
        @unlink($videoPath);
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/98765/media'
        && str_contains((string)($request->data()['video_url'] ?? ''), '/storage/socialize-temp/'));
});

it('ignores empty items in instagram carousel list before creating children', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::sequence()
            ->push(['id' => 'child-only'], 200)
            ->push(['id' => 'parent-only'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-carousel-sparse'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Sparse carousel')
        ->carousel(['   ', 'https://cdn.example.com/only.jpg'])
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-carousel-sparse');
    Http::assertSentCount(3);
});

it('deletes instagram post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    expect(Socialize::instagram()->delete('179123'))->toBeTrue();
});

it('throws for empty instagram post id on delete', function (): void {
    Http::fake();

    Socialize::instagram()->delete('   ');
})->throws(InvalidSharePayloadException::class, 'Instagram post id cannot be empty');

it('fails instagram share with invalid url', function (): void {
    Http::fake();

    Socialize::instagram()->imageUrl('bad-url')->share();
})->throws(InvalidSharePayloadException::class, 'does not exist or is not readable');

it('fails instagram video share when url format is invalid', function (): void {
    Http::fake();

    Socialize::instagram()->videoUrl('http://')->share();
})->throws(InvalidSharePayloadException::class, 'does not exist or is not readable');

it('fails instagram share without media', function (): void {
    Http::fake();

    Socialize::instagram()->message('Only text')->share();
})->throws(InvalidSharePayloadException::class, 'requires imageUrl or videoUrl when carousel is not used');

it('fails instagram share with empty payload', function (): void {
    Http::fake();

    Socialize::instagram()->share();
})->throws(InvalidSharePayloadException::class, 'requires imageUrl, videoUrl, or carousel items');

it('fails instagram share when required credentials are missing', function (): void {
    Http::fake();
    config()->set('socialize.providers.instagram.profiles.default.access_token');

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(InvalidConfigException::class, 'Missing required credential [access_token]');

it('fails instagram with invalid media type option', function (): void {
    Http::fake();

    Socialize::instagram()
        ->videoUrl('https://cdn.example.com/clip.mp4')
        ->option('media_type', 'podcast')
        ->share()
    ;
})->throws(InvalidSharePayloadException::class, 'must be one of VIDEO, REELS, STORIES');

it('throws api exception when instagram publish does not return id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['ok' => true], 200),
        'https://graph.facebook.com/v25.0/container-1*'        => Http::response(['status_code' => 'ERROR', 'status' => 'IN_PROGRESS'], 200),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(ApiException::class, 'did not return a media id');

it('retries instagram publish when media is not ready yet and then succeeds', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-retry'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::sequence()
            ->push([
                'error' => [
                    'message'       => 'Media ID is not available',
                    'code'          => 9007,
                    'error_subcode' => 2207027,
                ],
            ], 400)
            ->push(['id' => 'ig-retry-success'], 200),
        'https://graph.facebook.com/v25.0/container-retry*' => Http::response(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->imageUrl('https://cdn.example.com/retry.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-retry-success');
    Http::assertSentCount(4);
});

it('throws immediately for instagram publish errors that are not retryable not-ready states', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-plain-error'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['error' => ['message' => 'Unexpected publish error']], 500),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/plain-error.jpg')->share();
})->throws(ApiException::class, 'status 500');

it('throws on final instagram not-ready publish attempt when retries are exhausted', function (): void {
    config()->set('socialize.providers.instagram.publish_retry_attempts', 1);
    config()->set('socialize.providers.instagram.publish_retry_sleep_seconds', 0);

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-retry-final'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response([
            'error' => [
                'message'       => 'Media ID is not available',
                'code'          => 9007,
                'error_subcode' => 2207027,
            ],
        ], 400),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/retry-final.jpg')->share();
})->throws(ApiException::class, 'Media ID is not available');

it('retries instagram publish when not-ready state is inferred from error text and eta', function (): void {
    config()->set('socialize.providers.instagram.publish_retry_attempts', 2);
    config()->set('socialize.providers.instagram.publish_retry_sleep_seconds', 0);

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-retry-text'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::sequence()
            ->push([
                'error' => [
                    'message' => 'The media is not ready for publishing yet.',
                ],
            ], 400)
            ->push(['id' => 'ig-retry-text-success'], 200),
        'https://graph.facebook.com/v25.0/container-retry-text*' => Http::response([
            'status_code'                  => 'IN_PROGRESS',
            'status'                       => 'IN_PROGRESS',
            'estimated_time_to_completion' => 1,
        ], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->imageUrl('https://cdn.example.com/retry-text.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-retry-text-success');
});

it('retries instagram publish when not-ready state is inferred from user-facing error text', function (): void {
    config()->set('socialize.providers.instagram.publish_retry_attempts', 2);
    config()->set('socialize.providers.instagram.publish_retry_sleep_seconds', 0);

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-retry-user-text'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::sequence()
            ->push([
                'error' => [
                    'error_user_msg' => 'The media is not ready for publishing, please wait.',
                ],
            ], 400)
            ->push(['id' => 'ig-retry-user-text-success'], 200),
        'https://graph.facebook.com/v25.0/container-retry-user-text*' => Http::response([
            'status_code' => 'IN_PROGRESS',
            'status'      => 'IN_PROGRESS',
        ], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->imageUrl('https://cdn.example.com/retry-user-text.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-retry-user-text-success');
});

it('rethrows instagram container status polling failures when status check endpoint fails', function (): void {
    config()->set('socialize.providers.instagram.publish_retry_attempts', 2);
    config()->set('socialize.providers.instagram.publish_retry_sleep_seconds', 0);

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-retry-fail-status'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response([
            'error' => [
                'message'       => 'Media ID is not available',
                'code'          => 9007,
                'error_subcode' => 2207027,
            ],
        ], 400),
        'https://graph.facebook.com/v25.0/container-retry-fail-status*' => Http::response([
            'error' => [
                'message' => 'status endpoint unavailable',
            ],
        ], 500),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/retry-status-fail.jpg')->share();
})->throws(ApiException::class, 'status 500');

it('rethrows instagram container status polling 400 errors unrelated to estimated-time field support', function (): void {
    config()->set('socialize.providers.instagram.publish_retry_attempts', 2);
    config()->set('socialize.providers.instagram.publish_retry_sleep_seconds', 0);

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-retry-bad-code'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response([
            'error' => [
                'message'       => 'Media ID is not available',
                'code'          => 9007,
                'error_subcode' => 2207027,
            ],
        ], 400),
        'https://graph.facebook.com/v25.0/container-retry-bad-code*' => Http::response([
            'error' => [
                'message' => '(#999) Generic request error.',
                'code'    => 999,
            ],
        ], 400),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/retry-bad-code.jpg')->share();
})->throws(ApiException::class, 'status 400');

it('continues instagram video share when readiness polling reaches attempt limit without ready state', function (): void {
    config()->set('socialize.providers.instagram.publish_retry_attempts', 1);
    config()->set('socialize.providers.instagram.publish_retry_sleep_seconds', 0);

    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'                    => Http::response(['id' => 'container-video-attempt-limit'], 200),
        'https://graph.facebook.com/v25.0/container-video-attempt-limit*' => Http::response([
            'status_code' => 'IN_PROGRESS',
            'status'      => 'IN_PROGRESS',
        ], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-video-attempt-limit'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->videoUrl('https://cdn.example.com/video-attempt-limit.mp4')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-attempt-limit');
});

it('falls back instagram status polling when estimated time field is unsupported', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-retry-fallback'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::sequence()
            ->push([
                'error' => [
                    'message'       => 'Media ID is not available',
                    'code'          => 9007,
                    'error_subcode' => 2207027,
                ],
            ], 400)
            ->push(['id' => 'ig-retry-fallback-success'], 200),
        'https://graph.facebook.com/v25.0/container-retry-fallback*' => Http::sequence()
            ->push([
                'error' => [
                    'message' => '(#100) Tried accessing nonexisting field (estimated_time_to_completion)',
                    'code'    => 100,
                ],
            ], 400)
            ->push(['status_code' => 'FINISHED', 'status' => 'READY'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->imageUrl('https://cdn.example.com/retry-fallback.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-retry-fallback-success');
    Http::assertSentCount(5);
});

it('throws api exception when instagram publish status query has no status details', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['ok' => true], 200),
        'https://graph.facebook.com/v25.0/container-1*'        => Http::response([], 200),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(ApiException::class, 'Instagram API did not return a media id after publishing.');

it('throws when instagram container creation response has no id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::response(['ok' => true], 200),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(ApiException::class, 'did not return a container id');

it('throws when instagram carousel child container response has no id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::response(['ok' => true], 200),
    ]);

    Socialize::instagram()
        ->message('Carousel')
        ->carousel([
            'https://cdn.example.com/1.jpg',
            'https://cdn.example.com/2.jpg',
        ])
        ->share()
    ;
})->throws(ApiException::class, 'did not return a child container id');

it('throws when instagram carousel parent container response has no id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['ok' => true], 200),
    ]);

    Socialize::instagram()
        ->message('Carousel')
        ->carousel([
            'https://cdn.example.com/1.jpg',
            'https://cdn.example.com/2.jpg',
        ])
        ->share()
    ;
})->throws(ApiException::class, 'did not return a parent carousel container id');
