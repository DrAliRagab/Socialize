<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Providers\BaseProvider;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

function makeBaseProviderStub(array $providerConfig = [], array $credentials = ['token' => ' abc ']): object
{
    return new class($providerConfig, $credentials, ['timeout' => 1, 'connect_timeout' => 1, 'retries' => 1, 'retry_sleep_ms' => 1], 'default') extends BaseProvider {
        public function callSend(string $method, string $path, array $data = [], array $headers = []): Response
        {
            return $this->send($method, $path, $data, $headers);
        }

        /**
         * @return array<string, mixed>
         */
        public function callDecode(Response $response): array
        {
            return $this->decode($response);
        }

        public function callCredential(string $key): ?string
        {
            return $this->credential($key);
        }

        public function callRequireCredentials(string ...$keys): void
        {
            $this->requireCredentials(...$keys);
        }

        public function callGraphVersion(): string
        {
            return $this->graphVersion();
        }

        /**
         * @return list<array{source: string, type: ?string}>
         */
        public function callMediaSourcesFromPayload(SharePayload $sharePayload): array
        {
            return $this->mediaSourcesFromPayload($sharePayload);
        }

        /**
         * @return array{url: string, cleanup: callable(): void}
         */
        public function callMakeTemporaryPublicUrlForLocalPath(string $source, string $context): array
        {
            return $this->makeTemporaryPublicUrlForLocalPath($source, $context);
        }

        /**
         * @return array{contents: string, mime_type: ?string, file_name: string, size: int}
         */
        public function callLoadBinaryMediaSource(string $source): array
        {
            return $this->loadBinaryMediaSource($source);
        }

        public function callInferMediaType(string $source, ?string $typeHint = null, ?string $mimeType = null): string
        {
            return $this->inferMediaType($source, $typeHint, $mimeType);
        }

        /**
         * @return array{source: string, cleanup: callable(): void}
         */
        public function callPrepareUploadSource(string $source): array
        {
            return $this->prepareUploadSource($source);
        }

        protected function baseUrl(): string
        {
            return 'https://fake.test';
        }

        protected function providerName(): string
        {
            return 'fake';
        }

        protected function provider(): Provider
        {
            return Provider::Facebook;
        }
    };
}

it('supports all configured HTTP verbs in base provider', function (): void {
    Http::fake([
        'https://fake.test/*' => Http::response(['ok' => true], 200),
    ]);

    $provider = makeBaseProviderStub();

    expect($provider->callSend('GET', '/one')->status())->toBe(200)
        ->and($provider->callSend('POST', '/two')->status())->toBe(200)
        ->and($provider->callSend('DELETE', '/three')->status())->toBe(200)
        ->and($provider->callSend('PUT', '/four')->status())->toBe(200)
        ->and($provider->callSend('PATCH', '/five')->status())->toBe(200)
    ;
});

it('wraps unsupported HTTP method in api exception', function (): void {
    $provider = makeBaseProviderStub();

    $provider->callSend('TRACE', '/nope');
})->throws(ApiException::class, 'request failed before receiving a valid response');

it('throws api exception on failed response', function (): void {
    Http::fake([
        'https://fake.test/*' => Http::response(['message' => 'bad request'], 400),
    ]);

    $provider = makeBaseProviderStub();

    $provider->callSend('GET', '/bad');
})->throws(ApiException::class, 'status 400');

it('decodes response arrays and falls back to empty array for non arrays', function (): void {
    Http::fake([
        'https://fake.test/json' => Http::response(['ok' => true], 200),
        'https://fake.test/text' => Http::response('plain', 200),
    ]);

    $provider = makeBaseProviderStub();

    $json = $provider->callSend('GET', '/json');
    $text = $provider->callSend('GET', '/text');

    expect($provider->callDecode($json))->toBe(['ok' => true])
        ->and($provider->callDecode($text))->toBe([])
    ;
});

it('resolves credentials and validates required credentials', function (): void {
    $provider = makeBaseProviderStub([], ['token' => ' abc ', 'empty' => '']);

    expect($provider->callCredential('token'))->toBe('abc')
        ->and($provider->callCredential('empty'))->toBeNull()
    ;

    $provider->callRequireCredentials('missing');
})->throws(InvalidConfigException::class, 'Missing required credential [missing]');

it('returns configured graph version or default', function (): void {
    $configured = makeBaseProviderStub(['graph_version' => ' v77.0 ']);
    $default    = makeBaseProviderStub();

    expect($configured->callGraphVersion())->toBe('v77.0')
        ->and($default->callGraphVersion())->toBe('v25.0')
    ;
});

it('normalizes and deduplicates media sources from payload', function (): void {
    $provider = makeBaseProviderStub();

    $payload = new SharePayload(
        message: null,
        link: null,
        imageUrl: 'https://cdn.example.com/image.jpg',
        videoUrl: 'https://cdn.example.com/video.mp4',
        mediaIds: [],
        providerOptions: [
            'media_sources' => [
                'bad-entry',
                ['source' => '   '],
                ['source' => 123, 'type' => 'image'],
                ['source' => 'https://cdn.example.com/image.jpg', 'type' => 'image'],
                ['source' => 'https://cdn.example.com/image.jpg', 'type' => 'image'],
            ],
        ],
        metadata: [],
    );

    expect($provider->callMediaSourcesFromPayload($payload))->toBe([
        ['source' => 'https://cdn.example.com/image.jpg', 'type' => 'image'],
        ['source' => 'https://cdn.example.com/video.mp4', 'type' => 'video'],
    ]);
});

it('creates and cleans temporary public url for local media', function (): void {
    Storage::fake('public');
    config()->set('socialize.temporary_media.disk', 'public');
    config()->set('socialize.temporary_media.directory', 'socialize-temp');
    config()->set('socialize.temporary_media.visibility', 'public');

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-temp-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for base provider temp-url test.');
    }

    $imagePath = $tempFile . '.jpg';
    rename($tempFile, $imagePath);
    file_put_contents($imagePath, 'image-bytes');

    $provider = makeBaseProviderStub();

    try
    {
        $temporary = $provider->callMakeTemporaryPublicUrlForLocalPath($imagePath, 'BaseProvider test');

        expect($temporary['url'])->toContain('/storage/socialize-temp/');

        $cleanup = $temporary['cleanup'];
        $cleanup();
    } finally
    {
        @unlink($imagePath);
    }

    expect(Storage::disk('public')->allFiles('socialize-temp'))->toBe([]);
});

it('throws config exceptions for invalid temporary media config types', function (): void {
    $provider = makeBaseProviderStub();
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-config-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for base provider config test.');
    }

    file_put_contents($tempFile, 'test');

    config()->set('socialize.temporary_media.disk', []);

    try
    {
        $provider->callMakeTemporaryPublicUrlForLocalPath($tempFile, 'invalid disk');
    } finally
    {
        @unlink($tempFile);
    }
})->throws(InvalidConfigException::class, 'disk must be a non-empty string');

it('throws for invalid temporary media directory and visibility config types', function (): void {
    $provider = makeBaseProviderStub();
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-config-2-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for base provider config type test.');
    }

    file_put_contents($tempFile, 'test');

    config()->set('socialize.temporary_media.disk', 'public');
    config()->set('socialize.temporary_media.directory', []);

    try
    {
        expect(fn (): mixed => $provider->callMakeTemporaryPublicUrlForLocalPath($tempFile, 'invalid directory'))
            ->toThrow(InvalidConfigException::class, 'directory must be a string')
        ;

        config()->set('socialize.temporary_media.directory', 'socialize-temp');
        config()->set('socialize.temporary_media.visibility', []);

        expect(fn (): mixed => $provider->callMakeTemporaryPublicUrlForLocalPath($tempFile, 'invalid visibility'))
            ->toThrow(InvalidConfigException::class, 'visibility must be a string')
        ;
    } finally
    {
        @unlink($tempFile);
    }
});

it('throws when temporary media URL cannot be generated as valid url', function (): void {
    Storage::fake('public');
    config()->set('socialize.temporary_media.disk', 'public');
    config()->set('socialize.temporary_media.directory', 'socialize-temp');
    config()->set('socialize.temporary_media.visibility', 'public');

    URL::shouldReceive('to')->andReturn('not-a-url');

    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-invalid-url-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for base provider invalid-url test.');
    }

    $imagePath = $tempFile . '.jpg';
    rename($tempFile, $imagePath);
    file_put_contents($imagePath, 'image-bytes');

    $provider = makeBaseProviderStub();

    try
    {
        $provider->callMakeTemporaryPublicUrlForLocalPath($imagePath, 'invalid url');
    } finally
    {
        @unlink($imagePath);
        URL::clearResolvedInstances();
    }
})->throws(InvalidSharePayloadException::class, 'Generated temporary media URL is invalid');

it('loads binary media from local and remote sources and handles failures', function (): void {
    $provider = makeBaseProviderStub();
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-binary-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for base provider binary test.');
    }

    $imagePath = $tempFile . '.png';
    rename($tempFile, $imagePath);
    file_put_contents($imagePath, 'png-bytes');

    Http::fake([
        'https://cdn.example.com/file-without-extension' => Http::response('remote-bytes', 200, ['Content-Type' => 'image/jpeg; charset=utf-8']),
        'https://cdn.example.com/empty'                  => Http::response('', 200),
        'https://cdn.example.com/fail'                   => Http::response(['error' => 'bad'], 500),
    ]);

    try
    {
        $local = $provider->callLoadBinaryMediaSource($imagePath);
        $url   = $provider->callLoadBinaryMediaSource('https://cdn.example.com/file-without-extension');

        expect($local['file_name'])->toBe(basename($imagePath))
            ->and($url['file_name'])->toBe('media.bin')
            ->and($url['mime_type'])->toBe('image/jpeg')
        ;
    } finally
    {
        @unlink($imagePath);
    }

    expect(fn (): mixed => $provider->callLoadBinaryMediaSource(''))->toThrow(InvalidSharePayloadException::class, 'cannot be empty');
    expect(fn (): mixed => $provider->callLoadBinaryMediaSource('/path/that/does/not/exist.jpg'))->toThrow(InvalidSharePayloadException::class, 'does not exist or is not readable');
    expect(fn (): mixed => $provider->callLoadBinaryMediaSource('https://cdn.example.com/empty'))->toThrow(InvalidSharePayloadException::class, 'is empty');
    expect(fn (): mixed => $provider->callLoadBinaryMediaSource('https://cdn.example.com/fail'))->toThrow(ApiException::class, 'status 500');

    Http::fake(fn (): mixed => throw new RuntimeException('network boom'));

    expect(fn (): mixed => $provider->callLoadBinaryMediaSource('https://cdn.example.com/boom'))
        ->toThrow(ApiException::class, 'media download failed before receiving a valid response')
    ;

    $emptyFile = tempnam(sys_get_temp_dir(), 'socialize-base-empty-');

    if (! \is_string($emptyFile))
    {
        throw new RuntimeException('Failed to create empty file for base provider empty-file test.');
    }

    try
    {
        expect(fn (): mixed => $provider->callLoadBinaryMediaSource($emptyFile))
            ->toThrow(InvalidSharePayloadException::class, 'empty or unreadable')
        ;
    } finally
    {
        @unlink($emptyFile);
    }
});

it('prepares upload source URL into temporary file and cleans it', function (): void {
    Http::fake([
        'https://cdn.example.com/upload-image' => Http::response('image-bytes', 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $provider = makeBaseProviderStub();
    $prepared = $provider->callPrepareUploadSource('https://cdn.example.com/upload-image');
    $path     = $prepared['source'];

    expect($path)->toContain(sys_get_temp_dir())
        ->and(basename((string)$path))->toContain('socialize-upload-')
        ->and(pathinfo((string)$path, \PATHINFO_EXTENSION))->toBe('jpg')
        ->and(file_exists($path))->toBeTrue()
    ;

    $cleanup = $prepared['cleanup'];
    $cleanup();

    expect(file_exists($path))->toBeFalse();
});

it('normalizes null content-type headers to null', function (): void {
    $provider         = makeBaseProviderStub();
    $reflectionMethod = new ReflectionClass(BaseProvider::class)->getMethod('normalizeContentType');

    expect($reflectionMethod->invoke($provider, null))->toBeNull();
});

it('returns local upload source unchanged and cleanup is a no-op', function (): void {
    $provider = makeBaseProviderStub();
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-prepare-local-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for local upload-source prep test.');
    }

    file_put_contents($tempFile, 'local-content');

    try
    {
        $prepared = $provider->callPrepareUploadSource($tempFile);

        expect($prepared['source'])->toBe($tempFile)
            ->and(file_exists($tempFile))->toBeTrue()
        ;

        $cleanup = $prepared['cleanup'];
        $cleanup();

        expect(file_exists($tempFile))->toBeTrue();
    } finally
    {
        @unlink($tempFile);
    }
});

it('throws when temporary media storage copy fails', function (): void {
    config()->set('socialize.temporary_media.disk', 'public');
    config()->set('socialize.temporary_media.directory', 'socialize-temp');
    config()->set('socialize.temporary_media.visibility', 'public');

    $provider = makeBaseProviderStub();
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-putfile-fail-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for putFileAs failure test.');
    }

    file_put_contents($tempFile, 'image-bytes');

    $diskMock = Mockery::mock();
    $diskMock->shouldReceive('putFileAs')->once()->andReturn(false);
    Storage::shouldReceive('disk')->once()->with('public')->andReturn($diskMock);

    try
    {
        $provider->callMakeTemporaryPublicUrlForLocalPath($tempFile, 'putFileAs failure');
    } finally
    {
        @unlink($tempFile);
        Storage::clearResolvedInstances();
        Mockery::close();
    }
})->throws(InvalidSharePayloadException::class, 'Failed to copy');

it('throws when temporary media url generation is empty and cleans stored file', function (): void {
    config()->set('socialize.temporary_media.disk', 'public');
    config()->set('socialize.temporary_media.directory', 'socialize-temp');
    config()->set('socialize.temporary_media.visibility', 'public');

    $provider = makeBaseProviderStub();
    $tempFile = tempnam(sys_get_temp_dir(), 'socialize-base-empty-url-');

    if (! \is_string($tempFile))
    {
        throw new RuntimeException('Failed to create temporary file for empty URL test.');
    }

    file_put_contents($tempFile, 'image-bytes');

    $diskMock = Mockery::mock();
    $diskMock->shouldReceive('putFileAs')->once()->andReturn('socialize-temp/stored-file.jpg');
    $diskMock->shouldReceive('url')->once()->with('socialize-temp/stored-file.jpg')->andReturn('   ');
    $diskMock->shouldReceive('delete')->once()->with('socialize-temp/stored-file.jpg');
    Storage::shouldReceive('disk')->times(3)->with('public')->andReturn($diskMock);

    try
    {
        $provider->callMakeTemporaryPublicUrlForLocalPath($tempFile, 'empty url');
    } finally
    {
        @unlink($tempFile);
        Storage::clearResolvedInstances();
        Mockery::close();
    }
})->throws(InvalidSharePayloadException::class, 'Could not generate a public URL');

it('throws when preparing upload source with empty value', function (): void {
    $provider = makeBaseProviderStub();

    $provider->callPrepareUploadSource('   ');
})->throws(InvalidSharePayloadException::class, 'Media source cannot be empty');

it('handles cleanup safely when prepared upload temporary file is already deleted', function (): void {
    Http::fake([
        'https://cdn.example.com/cleanup-already-deleted' => Http::response('image-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $provider = makeBaseProviderStub();
    $prepared = $provider->callPrepareUploadSource('https://cdn.example.com/cleanup-already-deleted');
    $path     = $prepared['source'];

    expect(file_exists($path))->toBeTrue();

    @unlink($path);
    $cleanup = $prepared['cleanup'];
    $cleanup();

    expect(file_exists($path))->toBeFalse();
});

it('maps media mime types to temporary upload file extensions', function (): void {
    $provider = makeBaseProviderStub();

    $cases = [
        'https://cdn.example.com/mime-map-jpg'  => ['image-bytes', 'image/jpeg', 'jpg'],
        'https://cdn.example.com/mime-map-png'  => ['image-bytes', 'image/png', 'png'],
        'https://cdn.example.com/mime-map-gif'  => ['image-bytes', 'image/gif', 'gif'],
        'https://cdn.example.com/mime-map-webp' => ['image-bytes', 'image/webp', 'webp'],
        'https://cdn.example.com/mime-map-mp4'  => ['video-bytes', 'video/mp4', 'mp4'],
        'https://cdn.example.com/mime-map-webm' => ['video-bytes', 'video/webm', 'webm'],
        'https://cdn.example.com/mime-map-mov'  => ['video-bytes', 'video/quicktime', 'mov'],
    ];

    foreach ($cases as $url => [$body, $contentType, $expectedExtension])
    {
        Http::fake([
            $url => Http::response($body, 200, ['Content-Type' => $contentType]),
        ]);

        $prepared = $provider->callPrepareUploadSource($url);
        $path     = $prepared['source'];

        expect(pathinfo((string)$path, \PATHINFO_EXTENSION))->toBe($expectedExtension)
            ->and(file_exists($path))->toBeTrue()
        ;

        $cleanup = $prepared['cleanup'];
        $cleanup();
    }
});

it('handles upload source preparation when mime type is missing or unsupported', function (): void {
    $provider = makeBaseProviderStub();

    Http::fake([
        'https://cdn.example.com/no-content-type' => Http::response('bytes', 200),
    ]);

    $binary = $provider->callLoadBinaryMediaSource('https://cdn.example.com/no-content-type');

    expect($binary['mime_type'])->toBeNull();

    $preparedNoHeader = $provider->callPrepareUploadSource('https://cdn.example.com/no-content-type');
    $noHeaderPath     = $preparedNoHeader['source'];

    expect(pathinfo((string)$noHeaderPath, \PATHINFO_EXTENSION))->toBe('');

    $cleanup = $preparedNoHeader['cleanup'];
    $cleanup();

    Http::fake([
        'https://cdn.example.com/unsupported-type' => Http::response('bytes', 200, ['Content-Type' => 'application/octet-stream']),
    ]);

    $preparedUnsupported = $provider->callPrepareUploadSource('https://cdn.example.com/unsupported-type');
    $unsupportedPath     = $preparedUnsupported['source'];

    expect(pathinfo((string)$unsupportedPath, \PATHINFO_EXTENSION))->toBe('');

    $cleanup = $preparedUnsupported['cleanup'];
    $cleanup();
});

it('infers media types from hints mime types and extensions', function (): void {
    $provider = makeBaseProviderStub();

    expect($provider->callInferMediaType('/tmp/file.unknown', 'image'))->toBe('image')
        ->and($provider->callInferMediaType('/tmp/file.unknown', 'video'))->toBe('video')
        ->and($provider->callInferMediaType('/tmp/file.unknown', null, 'image/png'))->toBe('image')
        ->and($provider->callInferMediaType('/tmp/file.unknown', null, 'video/mp4'))->toBe('video')
        ->and($provider->callInferMediaType('/tmp/file.jpeg'))->toBe('image')
        ->and($provider->callInferMediaType('/tmp/file.mov'))->toBe('video')
    ;

    expect(fn (): mixed => $provider->callInferMediaType('https://cdn.example.com'))
        ->toThrow(InvalidSharePayloadException::class, 'Unsupported media extension')
    ;

    expect(fn (): mixed => $provider->callInferMediaType('/tmp/file.xyz'))
        ->toThrow(InvalidSharePayloadException::class, 'Unsupported media extension')
    ;
});
