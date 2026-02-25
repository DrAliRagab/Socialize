<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use function is_array;
use function is_string;

use const PHP_EOL;

use function sprintf;

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

            $publishResponse = $this->decode($this->send('POST', sprintf('/%s/%s/media_publish', $version, $igId), [
                'access_token' => $accessToken,
                'creation_id'  => $creationId,
            ]));

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
        $mediaType  = $sharePayload->option('media_type', 'VIDEO');
        $configured = is_string($mediaType) ? mb_strtoupper($mediaType) : 'VIDEO';

        return match ($configured)
        {
            'VIDEO', 'REELS', 'STORIES' => $configured,
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
