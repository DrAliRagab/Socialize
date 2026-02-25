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
use Exception;

use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;

use const FILTER_VALIDATE_URL;

use function fopen;

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
use function is_bool;
use function is_dir;
use function is_int;
use function is_readable;
use function is_string;
use function parse_url;
use function pathinfo;

use const PATHINFO_EXTENSION;
use const PHP_URL_PATH;

use function sprintf;
use function str_contains;
use function strlen;
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
        } catch (Exception $exception)
        {
            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('%s request failed before receiving a valid response: %s', $this->providerName(), $exception->getMessage()),
                throwable: $exception,
            );
        }

        if ($response->failed())
        {
            throw ApiException::fromResponse($this->provider(), $response);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function sendBinary(string $method, string $url, string $contents, string $contentType, array $headers = []): Response
    {
        $method = mb_strtoupper($method);

        try
        {
            $response = Http::withHeaders($headers)
                ->timeout($this->intConfig('timeout', 15))
                ->connectTimeout($this->intConfig('connect_timeout', 10))
                ->retry(
                    $this->intConfig('retries', 1),
                    $this->intConfig('retry_sleep_ms', 150),
                    throw: false,
                )
                ->withBody($contents, $contentType)
                ->send($method, $url)
            ;
        } catch (Exception $exception)
        {
            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('%s request failed before receiving a valid response: %s', $this->providerName(), $exception->getMessage()),
                throwable: $exception,
            );
        }

        if ($response->failed())
        {
            throw ApiException::fromResponse($this->provider(), $response);
        }

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    protected function sendBinaryFile(string $method, string $url, string $filePath, string $contentType, array $headers = []): Response
    {
        if (! file_exists($filePath) || ! is_readable($filePath))
        {
            throw new InvalidSharePayloadException(sprintf('Media source path does not exist or is not readable [%s].', $filePath));
        }

        $method = mb_strtoupper($method);
        $handle = fopen($filePath, 'rb');

        // @codeCoverageIgnoreStart
        if ($handle === false)
        {
            throw new InvalidSharePayloadException(sprintf('Media source path does not exist or is not readable [%s].', $filePath));
        }

        // @codeCoverageIgnoreEnd

        try
        {
            $response = Http::withHeaders($headers)
                ->timeout($this->intConfig('timeout', 15))
                ->connectTimeout($this->intConfig('connect_timeout', 10))
                ->send($method, $url, [
                    'body' => $handle,
                ])
            ;
        } catch (Exception $exception)
        {
            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('%s request failed before receiving a valid response: %s', $this->providerName(), $exception->getMessage()),
                throwable: $exception,
            );
        } finally
        {
            fclose($handle);
        }

        if ($response->failed())
        {
            throw ApiException::fromResponse($this->provider(), $response);
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $headers
     * @param array{name: string, contents: string, filename: string}|null $attachment
     */
    protected function sendMultipart(string $method, string $url, array $fields, array $headers = [], ?array $attachment = null): Response
    {
        $method = mb_strtoupper($method);

        /** @var array<int, array{name: string, contents: string, filename?: string}> $multipart */
        $multipart = [];

        foreach ($fields as $name => $value)
        {
            if (is_bool($value))
            {
                $value = $value ? 'true' : 'false';
            }

            if (is_int($value))
            {
                $value = (string)$value;
            }

            if (! is_string($value))
            {
                continue;
            }

            $multipart[] = [
                'name'     => (string)$name,
                'contents' => $value,
            ];
        }

        if (is_array($attachment))
        {
            $multipart[] = [
                'name'     => $attachment['name'],
                'contents' => $attachment['contents'],
                'filename' => $attachment['filename'],
            ];
        }

        try
        {
            $response = Http::withHeaders($headers)
                ->timeout($this->intConfig('timeout', 15))
                ->connectTimeout($this->intConfig('connect_timeout', 10))
                ->retry(
                    $this->intConfig('retries', 1),
                    $this->intConfig('retry_sleep_ms', 150),
                    throw: false,
                )
                ->send($method, $url, [
                    'multipart' => $multipart,
                ])
            ;
        } catch (Exception $exception)
        {
            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('%s request failed before receiving a valid response: %s', $this->providerName(), $exception->getMessage()),
                throwable: $exception,
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
                throw InvalidConfigException::missingCredential($key, $this->providerName(), $this->profile);
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

        foreach ($sharePayload->mediaSources() as $mediaSource)
        {
            $source = mb_trim($mediaSource['source']);
            $type   = $mediaSource['type'];

            $normalizedType = is_string($type) && mb_trim($type) !== ''
                ? mb_strtolower(mb_trim($type))
                : null;

            $this->appendUniqueMediaSource(
                $sources,
                $source,
                $normalizedType,
            );
        }

        $legacySources = $sharePayload->option('media_sources');

        if (is_array($legacySources))
        {
            foreach ($legacySources as $legacySource)
            {
                if (! is_array($legacySource))
                {
                    continue;
                }

                $source = $legacySource['source'] ?? null;
                $type   = $legacySource['type']   ?? null;

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

        $disk       = $this->temporaryMediaConfigValue('disk', 'public');
        $directory  = $this->temporaryMediaConfigValue('directory', 'socialize-temp');
        $visibility = $this->temporaryMediaConfigValue('visibility', 'public');

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
            } catch (Exception $exception)
            {
                throw ApiException::invalidResponse(
                    $this->provider(),
                    sprintf('%s media download failed before receiving a valid response: %s', $this->providerName(), $exception->getMessage()),
                    throwable: $exception,
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
                'size'      => strlen($contents),
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

        return [
            'contents'  => $contents,
            'mime_type' => $mimeType,
            'file_name' => $fileName,
            'size'      => strlen($contents),
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

        $pathFromUrl = parse_url($source, PHP_URL_PATH);
        $fileName    = is_string($pathFromUrl) && mb_trim($pathFromUrl) !== ''
            ? basename($pathFromUrl)
            : 'media.bin';

        if ($fileName === '' || ! str_contains($fileName, '.'))
        {
            $fileName = 'media.bin';
        }

        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $temporaryPath = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'socialize-upload-'
            . Str::uuid()->toString();

        if ($extension !== '')
        {
            $temporaryPath .= '.' . $extension;
        }

        if (is_dir($temporaryPath))
        {
            throw new InvalidSharePayloadException(
                sprintf('Unable to create temporary downloaded media file for source [%s].', $source),
            );
        }

        $pendingRequest = Http::accept('*/*')
            ->timeout($this->intConfig('timeout', 15))
            ->connectTimeout($this->intConfig('connect_timeout', 10))
            ->retry(
                $this->intConfig('retries', 1),
                $this->intConfig('retry_sleep_ms', 150),
                throw: false,
            )
            ->sink($temporaryPath)
        ;

        try
        {
            $response = $pendingRequest->get($source);
        } catch (Exception $exception)
        {
            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('%s media download failed before receiving a valid response: %s', $this->providerName(), $exception->getMessage()),
                throwable: $exception,
            );
        }

        if ($response->failed())
        {
            @unlink($temporaryPath);

            throw ApiException::fromResponse($this->provider(), $response);
        }

        $downloadedSize = filesize($temporaryPath);

        if (! is_int($downloadedSize) || $downloadedSize <= 0)
        {
            $body = $response->body();

            if ($body !== '')
            {
                $written = @file_put_contents($temporaryPath, $body);

                if ($written === false || $written <= 0)
                {
                    @unlink($temporaryPath);

                    throw new InvalidSharePayloadException(
                        sprintf('Unable to create temporary downloaded media file for source [%s].', $source),
                    );
                }

                $downloadedSize = filesize($temporaryPath);
            }
        }

        if (! is_int($downloadedSize) || $downloadedSize <= 0)
        {
            @unlink($temporaryPath);

            throw new InvalidSharePayloadException(sprintf('Downloaded media from [%s] is empty.', $source));
        }

        if ($extension === '' || $extension === 'bin')
        {
            $extension = $this->extensionFromMimeType($this->normalizeContentType($response->header('Content-Type')));

            if ($extension !== '')
            {
                $renamedTemporaryPath = $temporaryPath . '.' . $extension;

                if (@rename($temporaryPath, $renamedTemporaryPath))
                {
                    $temporaryPath = $renamedTemporaryPath;
                }
            }
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

    private function temporaryMediaConfigValue(string $key, mixed $default): mixed
    {
        $temporaryMediaConfig = $this->httpConfig['temporary_media'] ?? null;

        if (! is_array($temporaryMediaConfig))
        {
            return $default;
        }

        return $temporaryMediaConfig[$key] ?? $default;
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
