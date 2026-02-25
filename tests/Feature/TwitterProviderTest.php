<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('shares a text post on x', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'data' => [
                'id' => 'x-1',
            ],
        ], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->message('Hello X')
        ->link('https://example.com')
        ->share()
    ;

    expect($shareResult->id())->toBe('x-1')
        ->and($shareResult->url())->toBe('https://x.com/i/web/status/x-1')
    ;

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer x-token'));
});

it('shares a media x post with explicit media ids reply quote and poll', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response([
            'data' => [
                'id' => 'x-2',
            ],
        ], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->message('Vote now')
        ->mediaIds(['1', '2'])
        ->replyTo('44')
        ->quote('55')
        ->poll(['A', 'B'], 30)
        ->share()
    ;

    expect($shareResult->id())->toBe('x-2');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return ($data['reply']['in_reply_to_tweet_id'] ?? null) === '44'
            && ($data['quote_tweet_id'] ?? null)                === '55'
            && ($data['poll']['duration_minutes'] ?? null)      === 30
            && ($data['media']['media_ids'] ?? [])              === ['1', '2'];
    });
});

it('uploads image url to x media API automatically then shares', function (): void {
    Http::fake([
        'https://cdn.example.com/image.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*' => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['id' => 'm-id']], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-auto-image']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->message('Image upload')
        ->imageUrl('https://cdn.example.com/image.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('x-auto-image');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_category'] ?? null)             === 'tweet_image'
        && ($request->data()['shared'] ?? null)                     === false
        && ($request->data()['media_type'] ?? null)                 === 'image/jpeg');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/tweets'
        && ($request->data()['media']['media_ids'] ?? null)         === ['m-id']);
});

it('downloads URL media into a temporary file before x upload and cleans it after success', function (): void {
    $uploadTempFiles = static function (): array {
        $matches = glob(sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'socialize-upload-*');

        if (! \is_array($matches))
        {
            return [];
        }

        return $matches;
    };

    $before                  = $uploadTempFiles();
    $temporaryFileSeenOnInit = false;

    Http::fake(function (Request $request) use (&$temporaryFileSeenOnInit, $before, $uploadTempFiles) {
        if ($request->url() === 'https://cdn.example.com/temp-check.jpg')
        {
            return Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']);
        }

        if (str_starts_with($request->url(), 'https://api.x.com/2/media/upload'))
        {
            if ($request->url() === 'https://api.x.com/2/media/upload/initialize')
            {
                $temporaryFileSeenOnInit = array_values(array_diff($uploadTempFiles(), $before)) !== [];
            }

            static $uploadCall = 0;
            $uploadCall++;

            return match ($uploadCall)
            {
                1       => Http::response(['data' => ['id' => 'm-id']], 200),
                2       => Http::response('', 204),
                default => Http::response(['data' => ['id' => 'm-id']], 200),
            };
        }

        if ($request->url() === 'https://api.x.com/2/tweets')
        {
            return Http::response(['data' => ['id' => 'x-temp-check']], 200);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    $shareResult = Socialize::twitter()
        ->message('Temp file lifecycle')
        ->media('https://cdn.example.com/temp-check.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('x-temp-check')
        ->and($temporaryFileSeenOnInit)->toBeTrue()
        ->and(array_values(array_diff($uploadTempFiles(), $before)))->toBe([])
    ;
});

it('cleans temporary downloaded x media file when upload fails', function (): void {
    $uploadTempFiles = static function (): array {
        $matches = glob(sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'socialize-upload-*');

        if (! \is_array($matches))
        {
            return [];
        }

        return $matches;
    };

    $before                  = $uploadTempFiles();
    $temporaryFileSeenOnInit = false;

    Http::fake(function (Request $request) use (&$temporaryFileSeenOnInit, $before, $uploadTempFiles) {
        if ($request->url() === 'https://cdn.example.com/temp-fail.jpg')
        {
            return Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']);
        }

        if (str_starts_with($request->url(), 'https://api.x.com/2/media/upload'))
        {
            if ($request->url() === 'https://api.x.com/2/media/upload/initialize')
            {
                $temporaryFileSeenOnInit = array_values(array_diff($uploadTempFiles(), $before)) !== [];
            }

            return Http::response(['error' => 'init failed'], 500);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    expect(fn (): mixed => Socialize::twitter()
        ->media('https://cdn.example.com/temp-fail.jpg', 'image')
        ->share())
        ->toThrow(ApiException::class, 'status 500')
    ;

    expect($temporaryFileSeenOnInit)->toBeTrue()
        ->and(array_values(array_diff($uploadTempFiles(), $before)))->toBe([])
    ;
});

it('uploads local video source to x media API and waits for processing', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-x-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for x media test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, str_repeat('v', 256));

    Http::fake([
        'https://api.x.com/2/media/upload*' => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['processing_info' => ['state' => 'pending', 'check_after_secs' => 0]]], 200)
            ->push(['data' => ['processing_info' => ['state' => 'in_progress', 'check_after_secs' => 0]]], 200)
            ->push(['data' => ['processing_info' => ['state' => 'succeeded']]], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-auto-video']], 200),
    ]);

    try
    {
        $shareResult = Socialize::twitter()
            ->message('Video upload')
            ->media($videoPath, 'video')
            ->share()
        ;

        expect($shareResult->id())->toBe('x-auto-video');
    } finally
    {
        @unlink($videoPath);
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_category'] ?? null)             === 'tweet_video'
        && ($request->data()['shared'] ?? null)                     === false
        && ($request->data()['media_type'] ?? null)                 === 'video/mp4');
});

it('throws when x media source path is unreadable', function (): void {
    Http::fake();

    Socialize::twitter()
        ->media('/definitely/missing/socialize-image.jpg', 'image')
        ->share()
    ;
})->throws(InvalidSharePayloadException::class, 'does not exist or is not readable');

it('throws when x media processing fails', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-x-fail-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for x media failure test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, str_repeat('v', 256));

    Http::fake([
        'https://api.x.com/2/media/upload*' => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push([
                'data' => [
                    'processing_info' => [
                        'state' => 'failed',
                        'error' => [
                            'message' => 'unsupported media',
                        ],
                    ],
                ],
            ], 200),
    ]);

    try
    {
        Socialize::twitter()
            ->media($videoPath, 'video')
            ->share()
        ;
    } finally
    {
        @unlink($videoPath);
    }
})->throws(ApiException::class, 'media processing failed');

it('throws when x upload init does not return media id', function (): void {
    Http::fake([
        'https://cdn.example.com/no-id.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*' => Http::response([], 200),
    ]);

    Socialize::twitter()
        ->media('https://cdn.example.com/no-id.jpg', 'image')
        ->share()
    ;
})->throws(ApiException::class, 'init did not return a media id');

it('throws when x upload append fails', function (): void {
    Http::fake([
        'https://cdn.example.com/append-fail.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*'       => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push(['error' => 'append failed'], 400),
    ]);

    Socialize::twitter()
        ->media('https://cdn.example.com/append-fail.jpg', 'image')
        ->share()
    ;
})->throws(ApiException::class, 'status 400');

it('continues x share when finalize returns non-pending processing state', function (): void {
    Http::fake([
        'https://cdn.example.com/queued.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*'  => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['processing_info' => ['state' => 'queued']]], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-queued']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->message('Queued')
        ->media('https://cdn.example.com/queued.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('x-queued');
});

it('continues x share when status payload has no processing info', function (): void {
    Http::fake([
        'https://api.x.com/2/media/upload*' => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['processing_info' => ['state' => 'pending', 'check_after_secs' => '0']]], 200)
            ->push([], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-status-empty']], 200),
    ]);

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-x-status-empty-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for x status-empty test.');
    }

    $imagePath = $tempFile . '.jpg';
    rename($tempFile, $imagePath);
    file_put_contents($imagePath, 'image-bytes');

    try
    {
        $shareResult = Socialize::twitter()
            ->message('Status empty')
            ->media($imagePath, 'image')
            ->share()
        ;

        expect($shareResult->id())->toBe('x-status-empty');
    } finally
    {
        @unlink($imagePath);
    }
});

it('throws when x media processing times out', function (): void {
    $responseSequence = Http::sequence()
        ->push(['data' => ['id' => 'm-id']], 200)
        ->push('', 204)
        ->push(['data' => ['processing_info' => ['state' => 'pending', 'check_after_secs' => 0]]], 200)
    ;

    for ($i = 0; $i < 15; $i++)
    {
        $responseSequence->push(['data' => ['processing_info' => ['state' => 'in_progress', 'check_after_secs' => 0]]], 200);
    }

    Http::fake([
        'https://api.x.com/2/media/upload*' => $responseSequence,
    ]);

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-x-timeout-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for x timeout test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, str_repeat('v', 256));

    try
    {
        Socialize::twitter()
            ->media($videoPath, 'video')
            ->share()
        ;
    } finally
    {
        @unlink($videoPath);
    }
})->throws(ApiException::class, 'processing timed out');

it('infers x upload mime type from file extensions when content-type is missing', function (): void {
    Http::fake([
        'https://cdn.example.com/no-header.png' => Http::response('png-binary', 200),
        'https://cdn.example.com/no-header.mov' => Http::response('mov-binary', 200),
        'https://api.x.com/2/media/upload*'     => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['id' => 'm-id']], 200),
        'https://api.x.com/2/tweets' => Http::sequence()
            ->push(['data' => ['id' => 'x-png']], 200)
            ->push(['data' => ['id' => 'x-mov']], 200),
    ]);

    Socialize::twitter()
        ->message('png')
        ->media('https://cdn.example.com/no-header.png', 'image')
        ->share()
    ;

    Socialize::twitter()
        ->message('mov')
        ->media('https://cdn.example.com/no-header.mov', 'video')
        ->share()
    ;

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_type'] ?? null)                 === 'image/png');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_type'] ?? null)                 === 'video/quicktime');
});

it('uses detected local mime type for x upload when it matches media type', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-x-real-png-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for x detected mime test.');
    }

    $pngPath = $tempFile . '.png';
    rename($tempFile, $pngPath);
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8K6WkAAAAASUVORK5CYII=', true) ?: '');

    Http::fake([
        'https://api.x.com/2/media/upload*' => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['id' => 'm-id']], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-local-png']], 200),
    ]);

    try
    {
        $shareResult = Socialize::twitter()
            ->message('Local png mime')
            ->media($pngPath, 'image')
            ->share()
        ;

        expect($shareResult->id())->toBe('x-local-png');
    } finally
    {
        @unlink($pngPath);
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_type'] ?? null)                 === 'image/png');
});

it('throws when x upload command request fails with server error', function (): void {
    Http::fake([
        'https://cdn.example.com/server-fail.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*'       => Http::response(['error' => 'server fail'], 500),
    ]);

    Socialize::twitter()
        ->media('https://cdn.example.com/server-fail.jpg', 'image')
        ->share()
    ;
})->throws(ApiException::class, 'status 500');

it('handles x media status polling with positive check_after_secs', function (): void {
    Http::fake([
        'https://api.x.com/2/media/upload*' => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)
            ->push('', 204)
            ->push(['data' => ['processing_info' => ['state' => 'pending', 'check_after_secs' => 1]]], 200)
            ->push(['data' => ['processing_info' => ['state' => 'succeeded']]], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-sleep']], 200),
    ]);

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-x-sleep-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for x sleep polling test.');
    }

    $imagePath = $tempFile . '.jpg';
    rename($tempFile, $imagePath);
    file_put_contents($imagePath, 'image');

    try
    {
        $shareResult = Socialize::twitter()
            ->media($imagePath, 'image')
            ->share()
        ;

        expect($shareResult->id())->toBe('x-sleep');
    } finally
    {
        @unlink($imagePath);
    }
});

it('infers additional x upload mime branches for gif webp and webm', function (): void {
    Http::fake([
        'https://cdn.example.com/no-header.gif'  => Http::response('gif-binary', 200),
        'https://cdn.example.com/no-header.webp' => Http::response('webp-binary', 200),
        'https://cdn.example.com/no-header.webm' => Http::response('webm-binary', 200),
        'https://api.x.com/2/media/upload*'      => Http::sequence()
            ->push(['data' => ['id' => 'm-id']], 200)->push('', 204)->push(['data' => ['id' => 'm-id']], 200)
            ->push(['data' => ['id' => 'm-id']], 200)->push('', 204)->push(['data' => ['id' => 'm-id']], 200)
            ->push(['data' => ['id' => 'm-id']], 200)->push('', 204)->push(['data' => ['id' => 'm-id']], 200),
        'https://api.x.com/2/tweets' => Http::sequence()
            ->push(['data' => ['id' => 'x-gif']], 200)
            ->push(['data' => ['id' => 'x-webp']], 200)
            ->push(['data' => ['id' => 'x-webm']], 200),
    ]);

    Socialize::twitter()->message('gif')->media('https://cdn.example.com/no-header.gif', 'image')->share();
    Socialize::twitter()->message('webp')->media('https://cdn.example.com/no-header.webp', 'image')->share();
    Socialize::twitter()->message('webm')->media('https://cdn.example.com/no-header.webm', 'video')->share();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_type'] ?? null)                 === 'image/gif'
        && ($request->data()['media_category'] ?? null)             === 'tweet_gif');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_type'] ?? null)                 === 'image/webp');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.x.com/2/media/upload/initialize'
        && ($request->data()['media_type'] ?? null)                 === 'video/webm');
});

it('deletes x post', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets/*' => Http::response(['data' => ['deleted' => true]], 200),
    ]);

    expect(Socialize::twitter()->delete('1234'))->toBeTrue();
});

it('throws when x delete post id is empty', function (): void {
    Http::fake();

    Socialize::twitter()->delete('   ');
})->throws(InvalidSharePayloadException::class, 'X post id cannot be empty');

it('throws when x delete token is missing', function (): void {
    Http::fake();

    config()->set('socialize.providers.twitter.profiles.default.bearer_token');

    Socialize::twitter()->delete('123');
})->throws(InvalidConfigException::class, 'Missing required credential [bearer_token]');

it('fails x share when link is invalid', function (): void {
    Http::fake();

    Socialize::twitter()->message('hello')->link('bad-link')->share();
})->throws(InvalidSharePayloadException::class, 'must be a valid URL');

it('fails x share when resolved body is empty', function (): void {
    Http::fake();

    Socialize::twitter()->share();
})->throws(InvalidSharePayloadException::class, 'requires text, link, or media ids');

it('throws api exception for x failures', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['title' => 'Unauthorized'], 401),
    ]);

    Socialize::twitter()->message('fail')->share();
})->throws(ApiException::class, 'status 401');

it('throws when x response does not include id', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => []], 200),
    ]);

    Socialize::twitter()->message('missing id')->share();
})->throws(ApiException::class, 'did not return a post id');
