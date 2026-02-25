<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use function basename;

use const DIRECTORY_SEPARATOR;

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;

use function file_exists;
use function file_get_contents;
use function file_put_contents;

use const FILTER_VALIDATE_URL;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

use function in_array;

use InvalidArgumentException;

use function is_array;
use function is_int;
use function is_readable;
use function is_string;
use function mb_strlen;
use function parse_url;
use function pathinfo;

use const PATHINFO_EXTENSION;
use const PHP_URL_PATH;

use function sprintf;
use function str_contains;
use function sys_get_temp_dir;

use Throwable;

use function unlink;

abstract class BaseProvider
{
    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $httpConfig
     */
    public function __construct(
        protected readonly array $providerConfig,
        protected readonly array $credentials,
        protected readonly array $httpConfig,
        protected readonly string $profile,
    ) {}

    abstract protected function baseUrl(): string;

    abstract protected function providerName(): string;

    /**
     * @param array<string, string> $headers
     */
    protected function pendingRequest(array $headers = []): PendingRequest
    {
        return Http::withHeaders($headers)
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout($this->intConfig('timeout', 15))
            ->connectTimeout($this->intConfig('connect_timeout', 10))
            ->retry(
                $this->intConfig('retries', 1),
                $this->intConfig('retry_sleep_ms', 150),
                throw: false,
            )
        ;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function send(string $method, string $path, array $data = [], array $headers = []): Response
    {
        $method         = mb_strtoupper($method);
        $pendingRequest = $this->pendingRequest($headers);

        try
        {
            $response = match ($method)
            {
                'GET'    => $pendingRequest->get($path, $data),
                'POST'   => $pendingRequest->post($path, $data),
                'DELETE' => $pendingRequest->delete($path, $data),
                'PUT'    => $pendingRequest->put($path, $data),
                'PATCH'  => $pendingRequest->patch($path, $data),
                default  => throw new InvalidArgumentException(sprintf('Unsupported HTTP method [%s].', $method)),
            };
        } catch (Throwable $throwable)
        {
            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('%s request failed before receiving a valid response: %s', $this->providerName(), $throwable->getMessage()),
            );
        }

        if ($response->failed())
        {
            throw ApiException::fromResponse($this->provider(), $response);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json))
        {
            return [];
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    protected function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    protected function requireCredentials(string ...$keys): void
    {
        foreach ($keys as $key)
        {
            if ($this->credential($key) === null)
            {
                throw new InvalidConfigException(
                    sprintf('Missing required credential [%s] for provider [%s] and profile [%s].', $key, $this->providerName(), $this->profile),
                );
            }
        }
    }

    protected function graphVersion(): string
    {
        $version = $this->providerConfig['graph_version'] ?? null;

        return is_string($version) && mb_trim($version) !== '' ? mb_trim($version) : 'v25.0';
    }

    protected function isValidUrl(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @return list<array{source: string, type: ?string}>
     */
    protected function mediaSourcesFromPayload(SharePayload $sharePayload): array
    {
        /** @var list<array{source: string, type: ?string}> $sources */
        $sources = [];

        $rawSources = $sharePayload->option('media_sources');

        if (is_array($rawSources))
        {
            foreach ($rawSources as $rawSource)
            {
                if (! is_array($rawSource))
                {
                    continue;
                }

                $source = $rawSource['source'] ?? null;
                $type   = $rawSource['type']   ?? null;

                if (! is_string($source))
                {
                    continue;
                }

                if (mb_trim($source) === '')
                {
                    continue;
                }

                $normalizedType = is_string($type) && mb_trim($type) !== ''
                    ? mb_strtolower(mb_trim($type))
                    : null;

                $this->appendUniqueMediaSource(
                    $sources,
                    mb_trim($source),
                    $normalizedType,
                );
            }
        }

        $imageUrl = $sharePayload->imageUrl();

        if (is_string($imageUrl) && mb_trim($imageUrl) !== '')
        {
            $this->appendUniqueMediaSource($sources, mb_trim($imageUrl), 'image');
        }

        $videoUrl = $sharePayload->videoUrl();

        if (is_string($videoUrl) && mb_trim($videoUrl) !== '')
        {
            $this->appendUniqueMediaSource($sources, mb_trim($videoUrl), 'video');
        }

        return $sources;
    }

    /**
     * @return array{url: string, cleanup: callable(): void}
     */
    protected function makeTemporaryPublicUrlForLocalPath(string $source, string $context): array
    {
        $source = mb_trim($source);

        if (! file_exists($source) || ! is_readable($source))
        {
            throw new InvalidSharePayloadException(sprintf('%s path does not exist or is not readable [%s].', $context, $source));
        }

        $disk       = config('socialize.temporary_media.disk', 'public');
        $directory  = config('socialize.temporary_media.directory', 'socialize-temp');
        $visibility = config('socialize.temporary_media.visibility', 'public');

        if (! is_string($disk) || mb_trim($disk) === '')
        {
            throw new InvalidConfigException('socialize.temporary_media.disk must be a non-empty string.');
        }

        if (! is_string($directory))
        {
            throw new InvalidConfigException('socialize.temporary_media.directory must be a string.');
        }

        if (! is_string($visibility))
        {
            throw new InvalidConfigException('socialize.temporary_media.visibility must be a string.');
        }

        $directory  = mb_trim($directory);
        $visibility = mb_trim($visibility);

        $fileName  = Str::uuid()->toString();
        $extension = mb_trim(pathinfo($source, PATHINFO_EXTENSION));

        if ($extension !== '')
        {
            $fileName .= '.' . $extension;
        }

        $storedPath = Storage::disk($disk)->putFileAs(
            $directory,
            new File($source),
            $fileName,
            [
                'visibility' => $visibility === '' ? 'public' : $visibility,
            ],
        );

        if (! is_string($storedPath) || $storedPath === '')
        {
            throw new InvalidSharePayloadException(sprintf('Failed to copy [%s] into temporary media storage.', $source));
        }

        $url = Storage::disk($disk)->url($storedPath);

        if (mb_trim($url) === '')
        {
            Storage::disk($disk)->delete($storedPath);

            throw new InvalidSharePayloadException(sprintf('Could not generate a public URL for temporary media [%s].', $storedPath));
        }

        $url = mb_trim($url);

        if (! $this->isValidUrl($url))
        {
            $url = URL::to($url);
        }

        if (! $this->isValidUrl($url))
        {
            Storage::disk($disk)->delete($storedPath);

            throw new InvalidSharePayloadException(
                sprintf('Generated temporary media URL is invalid [%s]. Configure a valid app URL / disk URL.', $url),
            );
        }

        $cleanup = static function () use ($disk, $storedPath): void {
            try
            {
                Storage::disk($disk)->delete($storedPath);
            } catch (Throwable)
            {
            }
        };

        return [
            'url'     => $url,
            'cleanup' => $cleanup,
        ];
    }

    /**
     * @return array{contents: string, mime_type: ?string, file_name: string, size: int}
     */
    protected function loadBinaryMediaSource(string $source): array
    {
        $source = mb_trim($source);

        if ($source === '')
        {
            throw new InvalidSharePayloadException('Media source cannot be empty.');
        }

        if ($this->isValidUrl($source))
        {
            $pendingRequest = Http::accept('*/*')
                ->timeout($this->intConfig('timeout', 15))
                ->connectTimeout($this->intConfig('connect_timeout', 10))
                ->retry(
                    $this->intConfig('retries', 1),
                    $this->intConfig('retry_sleep_ms', 150),
                    throw: false,
                )
            ;

            try
            {
                $response = $pendingRequest->get($source);
            } catch (Throwable $throwable)
            {
                throw ApiException::invalidResponse(
                    $this->provider(),
                    sprintf('%s media download failed before receiving a valid response: %s', $this->providerName(), $throwable->getMessage()),
                );
            }

            if ($response->failed())
            {
                throw ApiException::fromResponse($this->provider(), $response);
            }

            $contents = $response->body();

            if ($contents === '')
            {
                throw new InvalidSharePayloadException(sprintf('Downloaded media from [%s] is empty.', $source));
            }

            $pathFromUrl = parse_url($source, PHP_URL_PATH);
            $fileName    = is_string($pathFromUrl) && mb_trim($pathFromUrl) !== '' ? basename($pathFromUrl) : 'media.bin';

            if ($fileName === '' || ! str_contains($fileName, '.'))
            {
                $fileName = 'media.bin';
            }

            return [
                'contents'  => $contents,
                'mime_type' => $this->normalizeContentType($response->header('Content-Type')),
                'file_name' => $fileName,
                'size'      => mb_strlen($contents),
            ];
        }

        if (! file_exists($source) || ! is_readable($source))
        {
            throw new InvalidSharePayloadException(sprintf('Media source path does not exist or is not readable [%s].', $source));
        }

        $contents = file_get_contents($source);

        if (! is_string($contents) || $contents === '')
        {
            throw new InvalidSharePayloadException(sprintf('Media source [%s] is empty or unreadable.', $source));
        }

        $detectedMime = mime_content_type($source);
        $mimeType     = is_string($detectedMime) && mb_trim($detectedMime) !== '' ? mb_trim($detectedMime) : null;
        $fileName     = basename($source);

        if ($fileName === '')
        {
            $fileName = 'media.bin';
        }

        return [
            'contents'  => $contents,
            'mime_type' => $mimeType,
            'file_name' => $fileName,
            'size'      => mb_strlen($contents),
        ];
    }

    /**
     * @return array{source: string, cleanup: callable(): void}
     */
    protected function prepareUploadSource(string $source): array
    {
        $source = mb_trim($source);

        if ($source === '')
        {
            throw new InvalidSharePayloadException('Media source cannot be empty.');
        }

        if (! $this->isValidUrl($source))
        {
            return [
                'source'  => $source,
                'cleanup' => static function (): void {},
            ];
        }

        $media     = $this->loadBinaryMediaSource($source);
        $extension = mb_strtolower(pathinfo($media['file_name'], PATHINFO_EXTENSION));

        if ($extension === '' || $extension === 'bin')
        {
            $extension = $this->extensionFromMimeType($media['mime_type']);
        }

        $temporaryPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'socialize-upload-'
            . Str::uuid()->toString();

        if ($extension !== '')
        {
            $temporaryPath .= '.' . $extension;
        }

        $written = file_put_contents($temporaryPath, $media['contents']);

        if ($written === false || $written <= 0)
        {
            throw new InvalidSharePayloadException(
                sprintf('Unable to create temporary downloaded media file for source [%s].', $source),
            );
        }

        $cleanup = static function () use ($temporaryPath): void {
            if (! file_exists($temporaryPath))
            {
                return;
            }

            try
            {
                unlink($temporaryPath);
            } catch (Throwable)
            {
            }
        };

        return [
            'source'  => $temporaryPath,
            'cleanup' => $cleanup,
        ];
    }

    protected function inferMediaType(string $source, ?string $typeHint = null, ?string $mimeType = null): string
    {
        $normalizedHint = is_string($typeHint) ? mb_strtolower(mb_trim($typeHint)) : null;

        if (is_string($normalizedHint) && $normalizedHint !== '')
        {
            if (str_contains($normalizedHint, 'image'))
            {
                return 'image';
            }

            if (str_contains($normalizedHint, 'video'))
            {
                return 'video';
            }
        }

        if (is_string($mimeType) && mb_trim($mimeType) !== '')
        {
            $normalizedMime = mb_strtolower(mb_trim($mimeType));

            if (str_contains($normalizedMime, 'image/'))
            {
                return 'image';
            }

            if (str_contains($normalizedMime, 'video/'))
            {
                return 'video';
            }
        }

        $path = parse_url($source, PHP_URL_PATH);

        if (! is_string($path) || mb_trim($path) === '')
        {
            $path = $source;
        }

        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === '')
        {
            throw new InvalidSharePayloadException(
                sprintf('Unable to infer media type from source [%s]. Provide media type explicitly (image|video).', $source),
            );
        }

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'heic', 'heif'], true))
        {
            return 'image';
        }

        if (in_array($extension, ['mp4', 'mov', 'm4v', 'avi', 'webm', 'mkv'], true))
        {
            return 'video';
        }

        throw new InvalidSharePayloadException(
            sprintf('Unsupported media extension [%s] for source [%s]. Provide media type explicitly (image|video).', $extension, $source),
        );
    }

    abstract protected function provider(): Provider;

    protected function intConfig(string $key, int $default): int
    {
        $value = $this->httpConfig[$key] ?? null;

        return is_int($value) ? $value : $default;
    }

    private function normalizeContentType(?string $contentType): ?string
    {
        if (! is_string($contentType))
        {
            return null;
        }

        $normalized = mb_trim($contentType);

        if ($normalized === '')
        {
            return null;
        }

        if (str_contains($normalized, ';'))
        {
            $normalized = mb_trim(explode(';', $normalized, 2)[0]);
        }

        return $normalized === '' ? null : $normalized;
    }

    private function extensionFromMimeType(?string $mimeType): string
    {
        if (! is_string($mimeType) || mb_trim($mimeType) === '')
        {
            return '';
        }

        return match (mb_strtolower(mb_trim($mimeType)))
        {
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/gif'       => 'gif',
            'image/webp'      => 'webp',
            'video/mp4'       => 'mp4',
            'video/webm'      => 'webm',
            'video/quicktime' => 'mov',
            default           => '',
        };
    }

    /**
     * @param list<array{source: string, type: ?string}> $items
     */
    private function appendUniqueMediaSource(array &$items, string $source, ?string $type): void
    {
        foreach ($items as $item)
        {
            if ($item['source'] === $source && $item['type'] === $type)
            {
                return;
            }
        }

        $items[] = [
            'source' => $source,
            'type'   => $type,
        ];
    }
}
