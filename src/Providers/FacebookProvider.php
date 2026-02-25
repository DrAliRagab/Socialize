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

use Illuminate\Support\Carbon;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;

use const PHP_EOL;

use function sprintf;

final class FacebookProvider extends BaseProvider implements ProviderDriver
{
    public function provider(): Provider
    {
        return Provider::Facebook;
    }

    public function share(SharePayload $sharePayload): ShareResult
    {
        $this->requireCredentials('page_id', 'access_token');

        if (! $sharePayload->hasAnyCoreContent())
        {
            throw new InvalidSharePayloadException('Facebook share requires at least one of message, link, imageUrl, or videoUrl.');
        }

        $pageId           = (string)$this->credential('page_id');
        $token            = (string)$this->credential('access_token');
        $version          = $this->graphVersion();
        $cleanupCallbacks = [];

        try
        {
            [$resolvedImageUrl, $resolvedVideoUrl] = $this->resolveMediaUrls($sharePayload, $cleanupCallbacks);

            if ($resolvedImageUrl !== null)
            {
                $this->ensureUrl($resolvedImageUrl, 'imageUrl');
                $endpoint = sprintf('/%s/%s/photos', $version, $pageId);

                $data = [
                    'access_token' => $token,
                    'url'          => $resolvedImageUrl,
                    'caption'      => $this->buildCaption($sharePayload),
                ];

                $this->applyPublishingOptions($sharePayload, $data);
            } elseif ($resolvedVideoUrl !== null)
            {
                $this->ensureUrl($resolvedVideoUrl, 'videoUrl');
                $endpoint = sprintf('/%s/%s/videos', $version, $pageId);

                $data = [
                    'access_token' => $token,
                    'file_url'     => $resolvedVideoUrl,
                    'description'  => $this->buildCaption($sharePayload),
                ];

                $this->applyPublishingOptions($sharePayload, $data);
            } else
            {
                $endpoint = sprintf('/%s/%s/feed', $version, $pageId);

                $data = [
                    'access_token' => $token,
                ];

                if ($sharePayload->message() !== null)
                {
                    $data['message'] = $sharePayload->message();
                }

                if ($sharePayload->link() !== null)
                {
                    $this->ensureUrl($sharePayload->link(), 'link');
                    $data['link'] = $sharePayload->link();
                }

                $this->applyPublishingOptions($sharePayload, $data);
            }

            $response = $this->decode($this->send('POST', $endpoint, $data));
            $id       = $response['id'] ?? $response['post_id'] ?? null;

            if (! is_string($id) || $id === '')
            {
                throw ApiException::invalidResponse($this->provider(), 'Facebook API did not return a post id.');
            }

            return new ShareResult(
                provider: $this->provider(),
                id: $id,
                url: sprintf('https://www.facebook.com/%s', $id),
                raw: $response,
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
            throw new InvalidSharePayloadException('Facebook post id cannot be empty.');
        }

        $endpoint = sprintf('/%s/%s', $this->graphVersion(), $postId);
        $response = $this->decode($this->send('DELETE', $endpoint, [
            'access_token' => (string)$this->credential('access_token'),
        ]));

        return (bool)($response['success'] ?? false);
    }

    protected function providerName(): string
    {
        return 'facebook';
    }

    protected function baseUrl(): string
    {
        $base = $this->providerConfig['base_url'] ?? 'https://graph.facebook.com';

        return is_string($base) ? mb_rtrim($base, '/') : 'https://graph.facebook.com';
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
            throw new InvalidSharePayloadException(sprintf('Facebook %s must be a valid URL.', $field));
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyPublishingOptions(SharePayload $sharePayload, array &$data): void
    {
        $published = $sharePayload->option('published');

        if (is_bool($published))
        {
            $data['published'] = $published;
        }

        $scheduledAt = $sharePayload->option('scheduled_at');

        if (is_int($scheduledAt) || is_string($scheduledAt))
        {
            $data['scheduled_publish_time'] = is_int($scheduledAt)
                ? $scheduledAt
                : Carbon::parse($scheduledAt)->timestamp;
            $data['published'] = false;
        }

        $targeting = $sharePayload->option('targeting');

        if (is_array($targeting) && $targeting !== [])
        {
            $data['targeting'] = $targeting;
        }
    }

    /**
     * @param list<callable(): void> $cleanupCallbacks
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveMediaUrls(SharePayload $sharePayload, array &$cleanupCallbacks): array
    {
        $imageUrl = $sharePayload->imageUrl();
        $videoUrl = $sharePayload->videoUrl();

        if ($imageUrl === null && $videoUrl === null)
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
            $temporary          = $this->makeTemporaryPublicUrlForLocalPath($imageUrl, 'Facebook image media');
            $imageUrl           = $temporary['url'];
            $cleanupCallbacks[] = $temporary['cleanup'];
        }

        if (is_string($videoUrl) && mb_trim($videoUrl) !== '' && ! $this->isValidUrl($videoUrl))
        {
            $temporary          = $this->makeTemporaryPublicUrlForLocalPath($videoUrl, 'Facebook video media');
            $videoUrl           = $temporary['url'];
            $cleanupCallbacks[] = $temporary['cleanup'];
        }

        return [$imageUrl, $videoUrl];
    }
}
