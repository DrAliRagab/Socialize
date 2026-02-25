<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('shares a linkedin post with commentary and link', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:1'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->message('Professional update')
        ->link('https://example.com/update')
        ->visibility('public')
        ->distribution('main_feed')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:1');
    expect($shareResult->url())->toBe('https://www.linkedin.com/feed/update/urn:li:share:1/');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Linkedin-Version', '202602')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && ($request->data()['author'] ?? null)                       === 'urn:li:person:123'
        && ($request->data()['content']['article']['source'] ?? null) === 'https://example.com/update'
        && ($request->data()['content']['article']['title'] ?? null)  === 'Professional update');
});

it('uses explicit link article title when provided via link second parameter', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:custom-title'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->message('Professional update')
        ->link('https://example.com/update', 'Custom LinkedIn Title')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:custom-title');

    Http::assertSent(fn (Request $request): bool => ($request->data()['content']['article']['title'] ?? null) === 'Custom LinkedIn Title');
});

it('shares linkedin post with media urn only', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:2']),
    ]);

    $shareResult = Socialize::linkedin()
        ->mediaUrn('urn:li:image:abc')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:2')
        ->and($shareResult->url())->toBe('https://www.linkedin.com/feed/update/urn:li:share:2/')
    ;
});

it('auto uploads linkedin image media from URL and uses returned URN', function (): void {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://cdn.example.com/linkedin-image.jpg')
        {
            return Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/images?action=initializeUpload')
        {
            return Http::response([
                'value' => [
                    'uploadUrl' => 'https://upload.linkedin.com/image-upload',
                    'image'     => 'urn:li:image:auto-1',
                ],
            ], 200);
        }

        if ($request->url() === 'https://upload.linkedin.com/image-upload')
        {
            return Http::response('', 201);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/posts')
        {
            return Http::response(['id' => 'urn:li:share:auto-image'], 201);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    $shareResult = Socialize::linkedin()
        ->message('Image post')
        ->media('https://cdn.example.com/linkedin-image.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:auto-image');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.linkedin.com/rest/posts'
        && ($request->data()['content']['media']['id'] ?? null)     === 'urn:li:image:auto-1');
});

it('downloads URL media into temporary file before linkedin upload and cleans it after success', function (): void {
    $uploadTempFiles = static function (): array {
        $matches = glob(sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'socialize-upload-*');

        if (! \is_array($matches))
        {
            return [];
        }

        return $matches;
    };

    $before                        = $uploadTempFiles();
    $temporaryFileSeenOnInitialize = false;

    Http::fake(function (Request $request) use (&$temporaryFileSeenOnInitialize, $before, $uploadTempFiles) {
        if ($request->url() === 'https://cdn.example.com/linkedin-temp-check.jpg')
        {
            return Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/images?action=initializeUpload')
        {
            $temporaryFileSeenOnInitialize = array_values(array_diff($uploadTempFiles(), $before)) !== [];

            return Http::response([
                'value' => [
                    'uploadUrl' => 'https://upload.linkedin.com/image-temp-check',
                    'image'     => 'urn:li:image:temp-check',
                ],
            ], 200);
        }

        if ($request->url() === 'https://upload.linkedin.com/image-temp-check')
        {
            return Http::response('', 201);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/posts')
        {
            return Http::response(['id' => 'urn:li:share:temp-check'], 201);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    $shareResult = Socialize::linkedin()
        ->message('LinkedIn temp file lifecycle')
        ->media('https://cdn.example.com/linkedin-temp-check.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:temp-check')
        ->and($temporaryFileSeenOnInitialize)->toBeTrue()
        ->and(array_values(array_diff($uploadTempFiles(), $before)))->toBe([])
    ;
});

it('cleans temporary downloaded linkedin media file when upload fails', function (): void {
    $uploadTempFiles = static function (): array {
        $matches = glob(sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'socialize-upload-*');

        if (! \is_array($matches))
        {
            return [];
        }

        return $matches;
    };

    $before                        = $uploadTempFiles();
    $temporaryFileSeenOnInitialize = false;

    Http::fake(function (Request $request) use (&$temporaryFileSeenOnInitialize, $before, $uploadTempFiles) {
        if ($request->url() === 'https://cdn.example.com/linkedin-temp-fail.jpg')
        {
            return Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/images?action=initializeUpload')
        {
            $temporaryFileSeenOnInitialize = array_values(array_diff($uploadTempFiles(), $before)) !== [];

            return Http::response([
                'value' => [
                    'uploadUrl' => 'https://upload.linkedin.com/image-temp-fail',
                    'image'     => 'urn:li:image:temp-fail',
                ],
            ], 200);
        }

        if ($request->url() === 'https://upload.linkedin.com/image-temp-fail')
        {
            return Http::response(['message' => 'upload blocked'], 403);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    expect(fn (): mixed => Socialize::linkedin()
        ->media('https://cdn.example.com/linkedin-temp-fail.jpg', 'image')
        ->share())
        ->toThrow(ApiException::class, 'status 403')
    ;

    expect($temporaryFileSeenOnInitialize)->toBeTrue()
        ->and(array_values(array_diff($uploadTempFiles(), $before)))->toBe([])
    ;
});

it('auto uploads linkedin video media from local file and finalizes upload', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-li-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for LinkedIn video test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, str_repeat('v', 512));
    $size = filesize($videoPath);

    if (! \is_int($size))
    {
        throw new RuntimeException('Failed to determine temporary video file size.');
    }

    Http::fake(function (Request $request) use ($size) {
        if ($request->url() === 'https://api.linkedin.com/rest/videos?action=initializeUpload')
        {
            return Http::response([
                'value' => [
                    'video'              => 'urn:li:video:auto-1',
                    'uploadToken'        => 'token-1',
                    'uploadInstructions' => [
                        [
                            'uploadUrl' => 'https://upload.linkedin.com/video-upload-1',
                            'firstByte' => 0,
                            'lastByte'  => $size - 1,
                        ],
                    ],
                ],
            ], 200);
        }

        if ($request->url() === 'https://upload.linkedin.com/video-upload-1')
        {
            return Http::response('', 201, ['ETag' => '"etag-1"']);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/videos?action=finalizeUpload')
        {
            return Http::response(['value' => ['success' => true]], 200);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/videos/urn%3Ali%3Avideo%3Aauto-1')
        {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if ($request->url() === 'https://api.linkedin.com/rest/posts')
        {
            return Http::response(['id' => 'urn:li:share:auto-video'], 201);
        }

        return Http::response(['error' => 'unexpected request'], 500);
    });

    try
    {
        $shareResult = Socialize::linkedin()
            ->message('Video post')
            ->media($videoPath, 'video')
            ->share()
        ;

        expect($shareResult->id())->toBe('urn:li:share:auto-video');
    } finally
    {
        @unlink($videoPath);
    }

    Http::assertSent(fn (Request $request): bool => $request->url()               === 'https://api.linkedin.com/rest/videos?action=finalizeUpload'
        && ($request->data()['finalizeUploadRequest']['video'] ?? null)           === 'urn:li:video:auto-1'
        && ($request->data()['finalizeUploadRequest']['uploadToken'] ?? null)     === 'token-1'
        && ($request->data()['finalizeUploadRequest']['uploadedPartIds'] ?? null) === ['etag-1']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.linkedin.com/rest/posts'
        && ($request->data()['content']['media']['id'] ?? null)     === 'urn:li:video:auto-1');
});

it('uses detected local mime type for linkedin media upload when it matches media type', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-li-real-png-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for LinkedIn detected mime test.');
    }

    $pngPath = $tempFile . '.png';
    rename($tempFile, $pngPath);
    file_put_contents($pngPath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO8K6WkAAAAASUVORK5CYII=', true) ?: '');

    Http::fake([
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => [
                'uploadUrl' => 'https://upload.linkedin.com/image-mime-detected',
                'image'     => 'urn:li:image:mime-detected',
            ],
        ], 200),
        'https://upload.linkedin.com/image-mime-detected' => Http::response('', 201),
        'https://api.linkedin.com/rest/posts'             => Http::response(['id' => 'urn:li:share:mime-detected'], 201),
    ]);

    try
    {
        $shareResult = Socialize::linkedin()
            ->media($pngPath, 'image')
            ->share()
        ;

        expect($shareResult->id())->toBe('urn:li:share:mime-detected');
    } finally
    {
        @unlink($pngPath);
    }

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://upload.linkedin.com/image-mime-detected'
        && $request->hasHeader('Content-Type', 'image/png'));
});

it('prefers explicit linkedin media URN and does not auto upload when both are provided', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:explicit-urn'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->mediaUrn('urn:li:image:explicit')
        ->media('https://cdn.example.com/ignored.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:explicit-urn');

    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://api.linkedin.com/rest/images?action=initializeUpload');
});

it('deletes linkedin post', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts/*' => Http::response('', 204),
    ]);

    expect(Socialize::linkedin()->delete('urn:li:share:2'))->toBeTrue();
});

it('deletes linkedin post when API returns success field', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts/*' => Http::response(['success' => true], 200),
    ]);

    expect(Socialize::linkedin()->delete('urn:li:share:2'))->toBeTrue();
});

it('throws for empty linkedin post id on delete', function (): void {
    Http::fake();

    Socialize::linkedin()->delete('   ');
})->throws(InvalidSharePayloadException::class, 'LinkedIn post id cannot be empty');

it('fails linkedin with invalid link', function (): void {
    Http::fake();

    Socialize::linkedin()->message('bad')->link('bad-url')->share();
})->throws(InvalidSharePayloadException::class, 'must be a valid URL');

it('fails linkedin when content is empty', function (): void {
    Http::fake();

    Socialize::linkedin()->share();
})->throws(InvalidSharePayloadException::class, 'requires text/link content or a media URN');

it('throws api exception for linkedin failures', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['message' => 'forbidden'], 403),
    ]);

    Socialize::linkedin()->message('Nope')->share();
})->throws(ApiException::class, 'status 403');

it('throws when linkedin response has no id', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['ok' => true], 201),
    ]);

    Socialize::linkedin()->message('No id')->share();
})->throws(ApiException::class, 'did not return a post id');

it('throws when linkedin version format is invalid', function (): void {
    Http::fake();
    config()->set('socialize.providers.linkedin.profiles.default.version', '2026-02');

    Socialize::linkedin()->message('Version test')->share();
})->throws(InvalidConfigException::class, 'LinkedIn version must use YYYYMM format');

it('uses default linkedin version header when version is not configured', function (): void {
    config()->set('socialize.providers.linkedin.profiles.default.version');

    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:default-version'], 201),
    ]);

    $shareResult = Socialize::linkedin()->message('Default version')->share();

    expect($shareResult->id())->toBe('urn:li:share:default-version');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Linkedin-Version', '202602'));
});

it('uses fallback article title when sharing linkedin link without a message', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:fallback-title'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->link('https://example.com/article')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:fallback-title');

    Http::assertSent(fn (Request $request): bool => ($request->data()['content']['article']['title'] ?? null) === 'Shared link');
});

it('prefers linkedin permalink from API body when available', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([
            'id'        => 'urn:li:share:from-body',
            'permalink' => 'https://www.linkedin.com/feed/update/urn:li:share:from-api/',
        ], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->message('Permalink')
        ->share()
    ;

    expect($shareResult->url())->toBe('https://www.linkedin.com/feed/update/urn:li:share:from-api/');
});

it('throws when linkedin required credentials are missing', function (): void {
    Http::fake();
    config()->set('socialize.providers.linkedin.profiles.default.access_token');

    Socialize::linkedin()->message('Missing token')->share();
})->throws(InvalidConfigException::class, 'Missing required credential [access_token]');

it('normalizes numeric linkedin author id into person URN', function (): void {
    config()->set('socialize.providers.linkedin.profiles.default.author', '101928801');

    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:numeric-author'], 201),
    ]);

    $shareResult = Socialize::linkedin()->message('Numeric author')->share();

    expect($shareResult->id())->toBe('urn:li:share:numeric-author');

    Http::assertSent(fn (Request $request): bool => ($request->data()['author'] ?? null) === 'urn:li:person:101928801');
});

it('throws when linkedin cannot infer media type from source', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-li-no-ext-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for LinkedIn media-type test.');
    }

    file_put_contents($tempFile, 'raw-bytes');

    Http::fake();

    try
    {
        Socialize::linkedin()
            ->media($tempFile)
            ->share()
        ;
    } finally
    {
        @unlink($tempFile);
    }
})->throws(InvalidSharePayloadException::class, 'Unable to infer media type');

it('throws when linkedin author is empty', function (): void {
    config()->set('socialize.providers.linkedin.profiles.default.author', '');
    Http::fake();

    Socialize::linkedin()->message('bad author')->share();
})->throws(InvalidConfigException::class, 'Missing required credential [author]');

it('throws when linkedin author format is invalid', function (): void {
    config()->set('socialize.providers.linkedin.profiles.default.author', 'not-a-urn');
    Http::fake();

    Socialize::linkedin()->message('bad author')->share();
})->throws(InvalidConfigException::class, 'must be a URN');

it('throws when linkedin image initialize response shape is invalid', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response(['value' => 'invalid'], 200),
    ]);

    Socialize::linkedin()->media('https://cdn.example.com/invalid-image.jpg', 'image')->share();
})->throws(ApiException::class, 'image initialize response is invalid');

it('throws when linkedin image initialize response misses upload fields', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response(['value' => ['uploadUrl' => '']], 200),
    ]);

    Socialize::linkedin()->media('https://cdn.example.com/missing-fields.jpg', 'image')->share();
})->throws(ApiException::class, 'missing upload URL or image URN');

it('throws when linkedin media upload endpoint fails', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.linkedin.com/rest/images?action=initializeUpload' => Http::response([
            'value' => [
                'uploadUrl' => 'https://upload.linkedin.com/image-fail',
                'image'     => 'urn:li:image:upload-fail',
            ],
        ], 200),
        'https://upload.linkedin.com/image-fail' => Http::response(['message' => 'forbidden'], 403),
    ]);

    Socialize::linkedin()->media('https://cdn.example.com/upload-fail.jpg', 'image')->share();
})->throws(ApiException::class, 'status 403');

it('throws when linkedin video initialize response shape is invalid', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('video-binary', 200, ['Content-Type' => 'video/mp4']),
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response(['value' => 'invalid'], 200),
    ]);

    Socialize::linkedin()->media('https://cdn.example.com/invalid-video.mp4', 'video')->share();
})->throws(ApiException::class, 'video initialize response is invalid');

it('throws when linkedin video initialize response misses video URN', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('video-binary', 200, ['Content-Type' => 'video/mp4']),
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response(['value' => []], 200),
    ]);

    Socialize::linkedin()->media('https://cdn.example.com/missing-video-urn.mp4', 'video')->share();
})->throws(ApiException::class, 'missing video URN');

it('throws when linkedin video initialize has invalid instructions and no fallback upload url', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('video-binary', 200, ['Content-Type' => 'video/mp4']),
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response([
            'value' => [
                'video'              => 'urn:li:video:no-fallback',
                'uploadInstructions' => [
                    'bad-entry',
                    ['uploadUrl' => '', 'firstByte' => 0, 'lastByte' => 10],
                ],
            ],
        ], 200),
    ]);

    Socialize::linkedin()->media('https://cdn.example.com/no-fallback.mp4', 'video')->share();
})->throws(ApiException::class, 'missing upload instructions');

it('throws when linkedin video upload instruction resolves to empty chunk', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-li-empty-chunk-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for LinkedIn empty-chunk test.');
    }

    $videoPath = $tempFile . '.mp4';
    rename($tempFile, $videoPath);
    file_put_contents($videoPath, 'small');

    Http::fake([
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response([
            'value' => [
                'video'              => 'urn:li:video:empty-chunk',
                'uploadInstructions' => [
                    ['uploadUrl' => 'https://upload.linkedin.com/empty-chunk', 'firstByte' => 1000, 'lastByte' => 1100],
                ],
            ],
        ], 200),
    ]);

    try
    {
        Socialize::linkedin()->media($videoPath, 'video')->share();
    } finally
    {
        @unlink($videoPath);
    }
})->throws(ApiException::class, 'upload chunk is empty');

it('finalizes linkedin video upload without status check when no uploaded part ids are returned', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('video-binary', 200, ['Content-Type' => 'video/mp4']),
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response([
            'value' => [
                'video'     => 'urn:li:video:no-etag',
                'uploadUrl' => 'https://upload.linkedin.com/no-etag',
            ],
        ], 200),
        'https://upload.linkedin.com/no-etag'                        => Http::response('', 201),
        'https://api.linkedin.com/rest/videos?action=finalizeUpload' => Http::response(['value' => ['success' => true]], 200),
        'https://api.linkedin.com/rest/posts'                        => Http::response(['id' => 'urn:li:share:no-etag'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->media('https://cdn.example.com/no-etag.mp4', 'video')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:no-etag');

    Http::assertNotSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.linkedin.com/rest/videos/urn%3Ali%3Avideo%3Ano-etag'));
});

it('normalizes non-string linkedin upload token and keeps fallback etag as uploaded part id', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('video-binary', 200, ['Content-Type' => 'video/mp4']),
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response([
            'value' => [
                'video'       => 'urn:li:video:fallback-etag',
                'uploadToken' => ['invalid'],
                'uploadUrl'   => 'https://upload.linkedin.com/fallback-etag',
            ],
        ], 200),
        'https://upload.linkedin.com/fallback-etag'                             => Http::response('', 201, ['ETag' => '"etag-fallback"']),
        'https://api.linkedin.com/rest/videos?action=finalizeUpload'            => Http::response(['value' => ['success' => true]], 200),
        'https://api.linkedin.com/rest/videos/urn%3Ali%3Avideo%3Afallback-etag' => Http::response(['status' => 'AVAILABLE'], 200),
        'https://api.linkedin.com/rest/posts'                                   => Http::response(['id' => 'urn:li:share:fallback-etag'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->media('https://cdn.example.com/fallback-etag.mp4', 'video')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:fallback-etag');

    Http::assertSent(fn (Request $request): bool => $request->url()               === 'https://api.linkedin.com/rest/videos?action=finalizeUpload'
        && ($request->data()['finalizeUploadRequest']['uploadToken'] ?? null)     === ''
        && ($request->data()['finalizeUploadRequest']['uploadedPartIds'] ?? null) === ['etag-fallback']);
});

it('skips linkedin video status failure handling when status is missing', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('video-binary', 200, ['Content-Type' => 'video/mp4']),
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response([
            'value' => [
                'video'              => 'urn:li:video:status-missing',
                'uploadToken'        => 'token',
                'uploadInstructions' => [
                    ['uploadUrl' => 'https://upload.linkedin.com/status-missing', 'firstByte' => 0, 'lastByte' => 4],
                ],
            ],
        ], 200),
        'https://upload.linkedin.com/status-missing'                             => Http::response('', 201, ['ETag' => '"etag-status-missing"']),
        'https://api.linkedin.com/rest/videos?action=finalizeUpload'             => Http::response(['value' => ['success' => true]], 200),
        'https://api.linkedin.com/rest/videos/urn%3Ali%3Avideo%3Astatus-missing' => Http::response([], 200),
        'https://api.linkedin.com/rest/posts'                                    => Http::response(['id' => 'urn:li:share:status-missing'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->media('https://cdn.example.com/status-missing.mp4', 'video')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:status-missing');
});

it('throws when linkedin video status indicates processing failure', function (): void {
    Http::fake([
        'https://cdn.example.com/*'                                    => Http::response('video-binary', 200, ['Content-Type' => 'video/mp4']),
        'https://api.linkedin.com/rest/videos?action=initializeUpload' => Http::response([
            'value' => [
                'video'              => 'urn:li:video:processing-failed',
                'uploadToken'        => 'token',
                'uploadInstructions' => [
                    ['uploadUrl' => 'https://upload.linkedin.com/processing-failed', 'firstByte' => 0, 'lastByte' => 4],
                ],
            ],
        ], 200),
        'https://upload.linkedin.com/processing-failed'                             => Http::response('', 201, ['ETag' => '"etag-processing-failed"']),
        'https://api.linkedin.com/rest/videos?action=finalizeUpload'                => Http::response(['value' => ['success' => true]], 200),
        'https://api.linkedin.com/rest/videos/urn%3Ali%3Avideo%3Aprocessing-failed' => Http::response([
            'status'                  => 'PROCESSING_FAILED',
            'processingFailureReason' => 'codec unsupported',
        ], 200),
    ]);

    Socialize::linkedin()
        ->media('https://cdn.example.com/processing-failed.mp4', 'video')
        ->share()
    ;
})->throws(ApiException::class, 'processing failed');

it('returns null linkedin url when id is not a URN and no permalink/url is present', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => '123'], 201),
    ]);

    $shareResult = Socialize::linkedin()->message('No URN')->share();

    expect($shareResult->url())->toBeNull();
});
