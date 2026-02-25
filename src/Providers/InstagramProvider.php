<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function mb_strtolower;

use const PHP_EOL;

use function sprintf;
use function str_contains;
use function usleep;

final class InstagramProvider extends BaseProvider implements ProviderDriver
{
    public function provider(): Provider
    {
        return Provider::Instagram;
    }

    public function share(SharePayload $sharePayload): ShareResult
    {
        $this->requireCredentials('ig_id', 'access_token');

        if (! $sharePayload->hasAnyCoreContent())
        {
            throw new InvalidSharePayloadException('Instagram share requires imageUrl, videoUrl, or carousel items.');
        }

        $igId             = (string)$this->credential('ig_id');
        $accessToken      = (string)$this->credential('access_token');
        $version          = $this->graphVersion();
        $cleanupCallbacks = [];

        try
        {
            $resolvedPayload = $this->resolveMediaPayload($sharePayload, $cleanupCallbacks);

            /** @var list<string>|null $carouselItems */
            $carouselItems = $resolvedPayload->option('carousel_items');

            if (is_array($carouselItems) && $carouselItems !== [])
            {
                $creationId = $this->createCarouselContainer($igId, $accessToken, $version, $resolvedPayload, $carouselItems);
            } else
            {
                $creationId = $this->createSingleContainer($igId, $accessToken, $version, $resolvedPayload);
            }

            if ($resolvedPayload->videoUrl() !== null)
            {
                $this->waitForContainerReady($creationId, $accessToken, $version);
            }

            $publishResponse = $this->publishWithRetry($igId, $accessToken, $version, $creationId);

            $id = $publishResponse['id'] ?? null;

            if (! is_string($id) || $id === '')
            {
                $statusDetail = $this->containerStatusDetail($creationId, $accessToken, $version);

                throw ApiException::invalidResponse(
                    $this->provider(),
                    sprintf('Instagram API did not return a media id after publishing.%s', $statusDetail),
                );
            }

            return new ShareResult(
                provider: $this->provider(),
                id: $id,
                url: null,
                raw: [
                    'container_id' => $creationId,
                    'publish'      => $publishResponse,
                ],
            );
        } finally
        {
            foreach ($cleanupCallbacks as $cleanupCallback)
            {
                $cleanupCallback();
            }
        }
    }

    public function delete(string $postId): bool
    {
        $this->requireCredentials('access_token');
        $postId = mb_trim($postId);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('Instagram post id cannot be empty.');
        }

        $response = $this->decode($this->send('DELETE', sprintf('/%s/%s', $this->graphVersion(), $postId), [
            'access_token' => (string)$this->credential('access_token'),
        ]));

        return (bool)($response['success'] ?? false);
    }

    protected function providerName(): string
    {
        return 'instagram';
    }

    protected function baseUrl(): string
    {
        $base = $this->providerConfig['base_url'] ?? 'https://graph.facebook.com';

        return is_string($base) ? mb_rtrim($base, '/') : 'https://graph.facebook.com';
    }

    private function createSingleContainer(string $igId, string $accessToken, string $version, SharePayload $sharePayload): string
    {
        $data = [
            'access_token' => $accessToken,
        ];

        $caption = $this->buildCaption($sharePayload);

        if ($caption !== null)
        {
            $data['caption'] = $caption;
        }

        if ($sharePayload->imageUrl() !== null)
        {
            $data['image_url'] = $sharePayload->imageUrl();

            $altText = $sharePayload->option('alt_text');

            if (is_string($altText) && mb_trim($altText) !== '')
            {
                $data['alt_text'] = mb_trim($altText);
            }
        } elseif ($sharePayload->videoUrl() !== null)
        {
            $data['video_url']  = $sharePayload->videoUrl();
            $data['media_type'] = $this->resolveVideoMediaType($sharePayload);
        } else
        {
            throw new InvalidSharePayloadException('Instagram share requires imageUrl or videoUrl when carousel is not used.');
        }

        $container  = $this->decode($this->send('POST', sprintf('/%s/%s/media', $version, $igId), $data));
        $creationId = $container['id'] ?? null;

        if (! is_string($creationId) || $creationId === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'Instagram API did not return a container id.');
        }

        return $creationId;
    }

    /**
     * @param list<string> $carouselItems
     */
    private function createCarouselContainer(string $igId, string $accessToken, string $version, SharePayload $sharePayload, array $carouselItems): string
    {
        $children = [];

        foreach ($carouselItems as $carouselItem)
        {
            $child = $this->decode($this->send('POST', sprintf('/%s/%s/media', $version, $igId), [
                'access_token'     => $accessToken,
                'image_url'        => $carouselItem,
                'is_carousel_item' => true,
            ]));

            $childId = $child['id'] ?? null;

            if (! is_string($childId) || $childId === '')
            {
                throw ApiException::invalidResponse($this->provider(), 'Instagram API did not return a child container id for carousel post.');
            }

            $children[] = $childId;
        }

        $parent = $this->decode($this->send('POST', sprintf('/%s/%s/media', $version, $igId), [
            'access_token' => $accessToken,
            'media_type'   => 'CAROUSEL',
            'children'     => implode(',', $children),
            'caption'      => $this->buildCaption($sharePayload),
        ]));

        $creationId = $parent['id'] ?? null;

        if (! is_string($creationId) || $creationId === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'Instagram API did not return a parent carousel container id.');
        }

        return $creationId;
    }

    private function resolveVideoMediaType(SharePayload $sharePayload): string
    {
        $mediaType  = $sharePayload->option('media_type', 'REELS');
        $configured = is_string($mediaType) ? mb_strtoupper($mediaType) : 'REELS';

        return match ($configured)
        {
            'REELS', 'STORIES' => $configured,
            'VIDEO' => 'REELS',
            default => throw new InvalidSharePayloadException('Instagram media_type must be one of VIDEO, REELS, STORIES.'),
        };
    }

    private function buildCaption(SharePayload $sharePayload): ?string
    {
        $parts = array_values(array_filter([
            $sharePayload->message(),
            $sharePayload->link(),
        ], fn (?string $value): bool => is_string($value) && mb_trim($value) !== ''));

        return $parts === [] ? null : implode(PHP_EOL, $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function publishWithRetry(string $igId, string $accessToken, string $version, string $creationId): array
    {
        $maxAttempts  = $this->publishRetryAttempts();
        $sleepSeconds = $this->publishRetrySleepSeconds();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++)
        {
            try
            {
                return $this->decode($this->send('POST', sprintf('/%s/%s/media_publish', $version, $igId), [
                    'access_token' => $accessToken,
                    'creation_id'  => $creationId,
                ]));
            } catch (ApiException $apiException)
            {
                $lastError = $apiException;

                if (! $this->isNotReadyPublishException($apiException))
                {
                    throw $apiException;
                }

                if ($attempt >= $maxAttempts - 1)
                {
                    throw $apiException;
                }

                $wait = $this->containerRetryWaitSeconds($creationId, $accessToken, $version, $sleepSeconds);

                if ($wait > 0)
                {
                    usleep($wait * 1_000_000);
                }
            }
        }

        throw ApiException::invalidResponse(Provider::Instagram, 'Instagram publish retry loop exited unexpectedly.');
    }

    private function isNotReadyPublishException(ApiException $apiException): bool
    {
        if ($apiException->status() !== 400)
        {
            return false;
        }

        $body     = $apiException->responseBody();
        $error    = $body['error'] ?? null;
        $code     = is_array($error) ? ($error['code'] ?? null) : null;
        $subCode  = is_array($error) ? ($error['error_subcode'] ?? null) : null;
        $message  = is_array($error) ? ($error['message'] ?? null) : null;
        $userText = is_array($error) ? ($error['error_user_msg'] ?? null) : null;

        if ($code === 9007 || $subCode === 2207027)
        {
            return true;
        }

        $text = '';

        if (is_string($message))
        {
            $text .= mb_strtolower($message) . ' ';
        }

        if (is_string($userText))
        {
            $text .= mb_strtolower($userText);
        }

        return str_contains($text, 'media id is not available')
            || str_contains($text, 'not ready for publishing');
    }

    private function containerRetryWaitSeconds(string $creationId, string $accessToken, string $version, int $default): int
    {
        return $this->waitSecondsFromContainerStatus(
            $this->fetchContainerStatus($creationId, $accessToken, $version),
            $default,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchContainerStatus(string $creationId, string $accessToken, string $version): array
    {
        try
        {
            return $this->decode($this->send('GET', sprintf('/%s/%s', $version, $creationId), [
                'access_token' => $accessToken,
                'fields'       => 'status_code,status,estimated_time_to_completion',
            ]));
        } catch (ApiException $apiException)
        {
            if (! $this->isUnsupportedEstimatedTimeFieldException($apiException))
            {
                throw $apiException;
            }

            return $this->decode($this->send('GET', sprintf('/%s/%s', $version, $creationId), [
                'access_token' => $accessToken,
                'fields'       => 'status_code,status',
            ]));
        }
    }

    /**
     * @param array<string, mixed> $status
     */
    private function waitSecondsFromContainerStatus(array $status, int $default): int
    {
        $statusCode = $status['status_code'] ?? null;

        if (is_string($statusCode))
        {
            $normalized = mb_strtolower(mb_trim($statusCode));

            if ($normalized === 'finished' || $normalized === 'ready')
            {
                return 0;
            }
        }

        $eta = $status['estimated_time_to_completion'] ?? null;

        if (is_int($eta) && $eta > 0)
        {
            return $eta;
        }

        return $default;
    }

    private function waitForContainerReady(string $creationId, string $accessToken, string $version): void
    {
        $attempt      = 0;
        $maxAttempts  = $this->publishRetryAttempts();
        $sleepSeconds = $this->publishRetrySleepSeconds();

        while ($attempt < $maxAttempts)
        {
            $status     = $this->fetchContainerStatus($creationId, $accessToken, $version);
            $statusCode = $status['status_code'] ?? null;

            if (is_string($statusCode))
            {
                $normalized = mb_strtolower(mb_trim($statusCode));

                if ($normalized === 'finished' || $normalized === 'ready')
                {
                    return;
                }

                if (in_array($normalized, ['error', 'expired', 'failed'], true))
                {
                    return;
                }
            }

            if ($attempt >= $maxAttempts - 1)
            {
                return;
            }

            $wait = $this->waitSecondsFromContainerStatus($status, $sleepSeconds);

            if ($wait > 0)
            {
                usleep($wait * 1_000_000);
            }

            $attempt++;
        }
    }

    private function isUnsupportedEstimatedTimeFieldException(ApiException $apiException): bool
    {
        if ($apiException->status() !== 400)
        {
            return false;
        }

        $body    = $apiException->responseBody();
        $error   = $body['error'] ?? null;
        $code    = is_array($error) ? ($error['code'] ?? null) : null;
        $message = is_array($error) ? ($error['message'] ?? null) : null;

        if ($code !== 100 || ! is_string($message))
        {
            return false;
        }

        $normalized = mb_strtolower($message);

        return str_contains($normalized, 'nonexisting field')
            && str_contains($normalized, 'estimated_time_to_completion');
    }

    private function publishRetryAttempts(): int
    {
        $configured = $this->providerConfig['publish_retry_attempts'] ?? 8;

        if (is_int($configured))
        {
            return max(1, $configured);
        }

        if (is_string($configured) && preg_match('/^-?\d+$/', $configured) === 1)
        {
            return max(1, (int)$configured);
        }

        return 8;
    }

    private function publishRetrySleepSeconds(): int
    {
        $configured = $this->providerConfig['publish_retry_sleep_seconds'] ?? 2;

        if (is_int($configured))
        {
            return max(0, $configured);
        }

        if (is_string($configured) && preg_match('/^-?\d+$/', $configured) === 1)
        {
            return max(0, (int)$configured);
        }

        return 2;
    }

    private function containerStatusDetail(string $creationId, string $accessToken, string $version): string
    {
        $statusBody = $this->decode($this->send('GET', sprintf('/%s/%s', $version, $creationId), [
            'access_token' => $accessToken,
            'fields'       => 'status_code,status',
        ]));

        $statusCode = $statusBody['status_code'] ?? null;
        $status     = $statusBody['status']      ?? null;
        $parts      = [];

        if (is_string($statusCode) && $statusCode !== '')
        {
            $parts[] = sprintf('status_code=%s', $statusCode);
        }

        if (is_string($status) && $status !== '')
        {
            $parts[] = sprintf('status=%s', $status);
        }

        if ($parts === [])
        {
            return '';
        }

        return ' Container status: ' . implode(', ', $parts);
    }

    /**
     * @param list<callable(): void> $cleanupCallbacks
     */
    private function resolveMediaPayload(SharePayload $sharePayload, array &$cleanupCallbacks): SharePayload
    {
        $imageUrl = $sharePayload->imageUrl();
        $videoUrl = $sharePayload->videoUrl();

        /** @var list<string>|null $carouselItems */
        $carouselItems         = $sharePayload->option('carousel_items');
        $resolvedCarouselItems = [];

        if (is_array($carouselItems) && $carouselItems !== [])
        {
            foreach ($carouselItems as $carouselItem)
            {
                if (mb_trim($carouselItem) === '')
                {
                    continue;
                }

                $carouselItem = mb_trim($carouselItem);

                if ($this->isValidUrl($carouselItem))
                {
                    $resolvedCarouselItems[] = $carouselItem;

                    continue;
                }

                $temporary               = $this->makeTemporaryPublicUrlForLocalPath($carouselItem, 'Instagram carousel media');
                $resolvedCarouselItems[] = $temporary['url'];
                $cleanupCallbacks[]      = $temporary['cleanup'];
            }
        }

        if ($imageUrl === null && $videoUrl === null && $resolvedCarouselItems === [])
        {
            foreach ($this->mediaSourcesFromPayload($sharePayload) as $source)
            {
                $mediaType = $this->inferMediaType($source['source'], $source['type'] ?? null);

                if ($mediaType === 'video')
                {
                    $videoUrl = $source['source'];
                } else
                {
                    $imageUrl = $source['source'];
                }

                break;
            }
        }

        if (is_string($imageUrl) && mb_trim($imageUrl) !== '' && ! $this->isValidUrl($imageUrl))
        {
            $temporary          = $this->makeTemporaryPublicUrlForLocalPath($imageUrl, 'Instagram image media');
            $imageUrl           = $temporary['url'];
            $cleanupCallbacks[] = $temporary['cleanup'];
        }

        if (is_string($videoUrl) && mb_trim($videoUrl) !== '' && ! $this->isValidUrl($videoUrl))
        {
            $temporary          = $this->makeTemporaryPublicUrlForLocalPath($videoUrl, 'Instagram video media');
            $videoUrl           = $temporary['url'];
            $cleanupCallbacks[] = $temporary['cleanup'];
        }

        $providerOptions = $sharePayload->providerOptions();

        if ($resolvedCarouselItems !== [])
        {
            $providerOptions['carousel_items'] = $resolvedCarouselItems;
        }

        return new SharePayload(
            message: $sharePayload->message(),
            link: $sharePayload->link(),
            imageUrl: $imageUrl,
            videoUrl: $videoUrl,
            mediaIds: $sharePayload->mediaIds(),
            providerOptions: $providerOptions,
            metadata: $sharePayload->metadata(),
        );
    }
}
