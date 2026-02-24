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

        $igId        = (string)$this->credential('ig_id');
        $accessToken = (string)$this->credential('access_token');
        $version     = $this->graphVersion();

        /** @var list<string>|null $carouselItems */
        $carouselItems = $sharePayload->option('carousel_items');

        if (is_array($carouselItems) && $carouselItems !== [])
        {
            $creationId = $this->createCarouselContainer($igId, $accessToken, $version, $sharePayload, $carouselItems);
        } else
        {
            $creationId = $this->createSingleContainer($igId, $accessToken, $version, $sharePayload);
        }

        $publishResponse = $this->decode($this->send('POST', sprintf('/%s/%s/media_publish', $version, $igId), [
            'access_token' => $accessToken,
            'creation_id'  => $creationId,
        ]));

        $id = $publishResponse['id'] ?? null;

        if (! is_string($id) || $id === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'Instagram API did not return a media id after publishing.');
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
    }

    public function delete(string $postId): bool
    {
        $this->requireCredentials('access_token');

        $response = $this->decode($this->send('DELETE', sprintf('/%s/%s', $this->graphVersion(), mb_trim($postId)), [
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
            $this->ensureUrl($sharePayload->imageUrl(), 'imageUrl');
            $data['image_url'] = $sharePayload->imageUrl();

            $altText = $sharePayload->option('alt_text');

            if (is_string($altText) && mb_trim($altText) !== '')
            {
                $data['alt_text'] = mb_trim($altText);
            }
        } elseif ($sharePayload->videoUrl() !== null)
        {
            $this->ensureUrl($sharePayload->videoUrl(), 'videoUrl');
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
            $this->ensureUrl($carouselItem, 'carousel item URL');

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

    private function ensureUrl(?string $value, string $field): void
    {
        if ($value === null || filter_var($value, FILTER_VALIDATE_URL) === false)
        {
            throw new InvalidSharePayloadException(sprintf('Instagram %s must be a valid URL.', $field));
        }
    }
}
