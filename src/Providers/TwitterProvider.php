<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use const FILTER_VALIDATE_URL;

use Illuminate\Support\Facades\Http;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strlen;
use function pathinfo;

use const PATHINFO_EXTENSION;
use const PHP_EOL;

use function sprintf;
use function str_contains;
use function usleep;

final class TwitterProvider extends BaseProvider implements ProviderDriver
{
    public function provider(): Provider
    {
        return Provider::Twitter;
    }

    public function share(SharePayload $sharePayload): ShareResult
    {
        $this->requireCredentials('bearer_token');

        if (! $sharePayload->hasAnyCoreContent())
        {
            throw new InvalidSharePayloadException('X share requires text, link, or media ids.');
        }

        $body     = [];
        $text     = $this->buildText($sharePayload);
        $mediaIds = $this->resolveMediaIds($sharePayload);

        if ($text !== null)
        {
            $body['text'] = $text;
        }

        if ($mediaIds !== [])
        {
            $body['media'] = [
                'media_ids' => $mediaIds,
            ];
        }

        $replyTo = $sharePayload->option('reply_to');

        if (is_string($replyTo) && $replyTo !== '')
        {
            $body['reply'] = [
                'in_reply_to_tweet_id' => $replyTo,
            ];
        }

        $quoteId = $sharePayload->option('quote_tweet_id');

        if (is_string($quoteId) && $quoteId !== '')
        {
            $body['quote_tweet_id'] = $quoteId;
        }

        $poll = $sharePayload->option('poll');

        if (is_array($poll) && $poll !== [])
        {
            $body['poll'] = $poll;
        }

        if ($body === [])
        {
            throw new InvalidSharePayloadException('X share payload resolved to empty body.');
        }

        $response = $this->decode($this->send('POST', '/2/tweets', $body, $this->headers()));
        $data     = $response['data'] ?? null;
        $id       = is_array($data) ? ($data['id'] ?? null) : null;

        if (! is_string($id) || $id === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'X API did not return a post id.');
        }

        return new ShareResult(
            provider: $this->provider(),
            id: $id,
            url: sprintf('https://x.com/i/web/status/%s', $id),
            raw: $response,
        );
    }

    public function delete(string $postId): bool
    {
        $this->requireCredentials('bearer_token');
        $postId = mb_trim($postId);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('X post id cannot be empty.');
        }

        $response = $this->decode($this->send('DELETE', sprintf('/2/tweets/%s', $postId), [], $this->headers()));
        $data     = $response['data'] ?? null;

        return (bool)(is_array($data) ? ($data['deleted'] ?? false) : false);
    }

    protected function providerName(): string
    {
        return 'twitter';
    }

    protected function baseUrl(): string
    {
        $base = $this->providerConfig['base_url'] ?? 'https://api.x.com';

        return is_string($base) ? mb_rtrim($base, '/') : 'https://api.x.com';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->credential('bearer_token'),
        ];
    }

    private function buildText(SharePayload $sharePayload): ?string
    {
        $parts = [];

        if (is_string($sharePayload->message()) && mb_trim($sharePayload->message()) !== '')
        {
            $parts[] = mb_trim($sharePayload->message());
        }

        if (is_string($sharePayload->link()) && mb_trim($sharePayload->link()) !== '')
        {
            if (filter_var($sharePayload->link(), FILTER_VALIDATE_URL) === false)
            {
                throw new InvalidSharePayloadException('X share link must be a valid URL.');
            }

            $parts[] = mb_trim($sharePayload->link());
        }

        if ($parts === [])
        {
            return null;
        }

        return implode(PHP_EOL . PHP_EOL, $parts);
    }

    /**
     * @return list<string>
     */
    private function resolveMediaIds(SharePayload $sharePayload): array
    {
        $mediaIds = $sharePayload->mediaIds();

        foreach ($this->mediaSourcesFromPayload($sharePayload) as $source)
        {
            $mediaId = $this->uploadMedia($source['source'], $source['type'] ?? null);

            if (! in_array($mediaId, $mediaIds, true))
            {
                $mediaIds[] = $mediaId;
            }
        }

        return $mediaIds;
    }

    private function uploadMedia(string $source, ?string $typeHint = null): string
    {
        $prepared = $this->prepareUploadSource($source);
        $cleanup  = $prepared['cleanup'];

        try
        {
            $media = $this->loadBinaryMediaSource($prepared['source']);

            $mediaType = $this->inferMediaType($source, $typeHint, $media['mime_type']);
            $mimeType  = $this->resolveMimeType($media['mime_type'], $mediaType, $media['file_name']);
            $mediaId   = $this->uploadInit($media['size'], $mimeType, $mediaType);

            $chunkSize = 5 * 1024 * 1024;
            $offset    = 0;
            $segment   = 0;

            while ($offset < $media['size'])
            {
                $chunk = mb_substr($media['contents'], $offset, $chunkSize);

                if ($chunk === '')
                {
                    break;
                }

                $this->uploadAppend($mediaId, $segment, $chunk, $media['file_name']);
                $offset += mb_strlen($chunk);
                $segment++;
            }

            $this->uploadFinalizeAndWait($mediaId);

            return $mediaId;
        } finally
        {
            $cleanup();
        }
    }

    private function uploadInit(int $size, string $mimeType, string $mediaType): string
    {
        $response = $this->decode($this->send('POST', '/2/media/upload/initialize', [
            'total_bytes'    => $size,
            'media_type'     => $mimeType,
            'media_category' => $this->resolveMediaCategory($mediaType, $mimeType),
            'shared'         => false,
        ], $this->headers()));

        $data    = $response['data'] ?? null;
        $mediaId = is_array($data) ? ($data['id'] ?? null) : null;

        if (! is_string($mediaId) || $mediaId === '')
        {
            $mediaId = $response['media_id_string'] ?? $response['media_id'] ?? null;
        }

        if (! is_string($mediaId) || $mediaId === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'X media upload init did not return a media id.');
        }

        return $mediaId;
    }

    private function uploadAppend(string $mediaId, int $segment, string $chunk, string $fileName): void
    {
        $response = Http::withHeaders($this->headers())
            ->baseUrl($this->baseUrl())
            ->timeout($this->intConfig('timeout', 15))
            ->connectTimeout($this->intConfig('connect_timeout', 10))
            ->retry(
                $this->intConfig('retries', 1),
                $this->intConfig('retry_sleep_ms', 150),
                throw: false,
            )
            ->attach('media', $chunk, $fileName)
            ->post(sprintf('/2/media/upload/%s/append', $mediaId), [
                'media_id'      => $mediaId,
                'segment_index' => $segment,
            ])
        ;

        if ($response->failed())
        {
            throw ApiException::fromResponse($this->provider(), $response);
        }
    }

    private function uploadFinalizeAndWait(string $mediaId): void
    {
        $finalizeResponse = $this->decode($this->send('POST', sprintf('/2/media/upload/%s/finalize', $mediaId), [], $this->headers()));
        $data             = $finalizeResponse['data'] ?? null;
        $processing       = is_array($data) ? ($data['processing_info'] ?? null) : null;

        if (! is_array($processing))
        {
            $processing = $finalizeResponse['processing_info'] ?? null;
        }

        if (! is_array($processing))
        {
            return;
        }

        /** @var array<string, mixed> $processing */
        $this->waitForMediaProcessing($mediaId, $processing);
    }

    /**
     * @param array<string, mixed> $processing
     */
    private function waitForMediaProcessing(string $mediaId, array $processing): void
    {
        $attempt = 0;

        while ($attempt < 15)
        {
            $rawState = $processing['state'] ?? null;
            $state    = is_string($rawState) ? mb_strtolower(mb_trim($rawState)) : '';

            if ($state === 'succeeded')
            {
                return;
            }

            if ($state === 'failed')
            {
                $error    = 'unknown error';
                $rawError = $processing['error'] ?? null;

                if (is_array($rawError))
                {
                    $rawErrorMessage = $rawError['message'] ?? null;

                    if (is_string($rawErrorMessage) && mb_trim($rawErrorMessage) !== '')
                    {
                        $error = mb_trim($rawErrorMessage);
                    }
                }

                throw ApiException::invalidResponse(
                    $this->provider(),
                    sprintf('X media processing failed for media id [%s]: %s', $mediaId, $error),
                );
            }

            if (! in_array($state, ['pending', 'in_progress'], true))
            {
                return;
            }

            $rawWaitSeconds = $processing['check_after_secs'] ?? 1;
            $waitSeconds    = is_int($rawWaitSeconds)
                ? $rawWaitSeconds
                : (
                    is_string($rawWaitSeconds) && preg_match('/^\d+$/', $rawWaitSeconds) === 1
                    ? (int)$rawWaitSeconds
                    : 1
                );

            if ($waitSeconds > 0)
            {
                usleep($waitSeconds * 1_000_000);
            }

            $status = $this->decode($this->send('GET', '/2/media/upload', [
                'media_id' => $mediaId,
                'command'  => 'STATUS',
            ], $this->headers()));

            $statusData     = $status['data'] ?? null;
            $nextProcessing = is_array($statusData) ? ($statusData['processing_info'] ?? null) : null;

            if (! is_array($nextProcessing))
            {
                $nextProcessing = $status['processing_info'] ?? null;
            }

            if (! is_array($nextProcessing))
            {
                return;
            }

            /** @var array<string, mixed> $processing */
            $processing = $nextProcessing;
            $attempt++;
        }

        throw ApiException::invalidResponse(
            $this->provider(),
            sprintf('X media processing timed out for media id [%s].', $mediaId),
        );
    }

    private function resolveMimeType(?string $detectedMime, string $mediaType, string $fileName): string
    {
        if (is_string($detectedMime) && mb_trim($detectedMime) !== '')
        {
            $detectedMime = mb_strtolower(mb_trim($detectedMime));

            if (
                ($mediaType === 'image' && str_contains($detectedMime, 'image/'))
                || ($mediaType === 'video' && str_contains($detectedMime, 'video/'))
            ) {
                return $detectedMime;
            }
        }

        $extension = mb_strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($mediaType === 'image')
        {
            return match ($extension)
            {
                'png'   => 'image/png',
                'gif'   => 'image/gif',
                'webp'  => 'image/webp',
                default => 'image/jpeg',
            };
        }

        return match ($extension)
        {
            'mov'   => 'video/quicktime',
            'webm'  => 'video/webm',
            default => 'video/mp4',
        };
    }

    private function resolveMediaCategory(string $mediaType, string $mimeType): string
    {
        if ($mediaType === 'video')
        {
            return 'tweet_video';
        }

        return $mimeType === 'image/gif' ? 'tweet_gif' : 'tweet_image';
    }
}
