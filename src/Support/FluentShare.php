<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Support;

use function count;

use DateTimeInterface;
use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Exceptions\UnsupportedFeatureException;
use DrAliRagab\Socialize\ValueObjects\CommentResult;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;
use Illuminate\Support\Carbon;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

final class FluentShare
{
    private const array SHARED_OPTION_KEYS = [
        'media_sources',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const array PROVIDER_OPTION_KEYS = [
        'facebook' => [
            'published',
            'scheduled_at',
            'targeting',
        ],
        'instagram' => [
            'alt_text',
            'carousel_items',
            'carousel_contains_video',
            'media_type',
        ],
        'twitter' => [
            'poll',
            'quote_tweet_id',
            'reply_to',
        ],
        'linkedin' => [
            'article_title',
            'distribution',
            'media_urn',
            'visibility',
        ],
    ];

    private ?string $message = null;

    private ?string $link = null;

    private ?string $imageUrl = null;

    private ?string $videoUrl = null;

    /** @var list<string> */
    private array $mediaIds = [];

    /** @var list<array{source: string, type: ?string}> */
    private array $mediaSources = [];

    /** @var array<string, mixed> */
    private array $providerOptions = [];

    /** @var array<string, mixed> */
    private array $metadata = [];

    public function __construct(
        private readonly Provider $provider,
        private readonly ProviderDriver $providerDriver,
        private readonly bool $strictOptionKeys = true,
    ) {}

    public function message(?string $message): self
    {
        $this->message = $this->normalizeNullableString($message);

        return $this;
    }

    public function link(?string $link, ?string $articleTitle = null): self
    {
        $this->link = $this->normalizeNullableString($link);

        if ($articleTitle !== null)
        {
            $articleTitle = $this->normalizeNullableString($articleTitle);

            if ($articleTitle === null)
            {
                throw new InvalidSharePayloadException('link article title cannot be empty when provided.');
            }

            $this->option('article_title', $articleTitle);
        }

        return $this;
    }

    public function imageUrl(?string $imageUrl): self
    {
        $this->imageUrl = $this->normalizeNullableString($imageUrl);

        return $this;
    }

    public function videoUrl(?string $videoUrl): self
    {
        $this->videoUrl = $this->normalizeNullableString($videoUrl);

        return $this;
    }

    public function mediaId(string $mediaId): self
    {
        $mediaId = mb_trim($mediaId);

        if ($mediaId === '')
        {
            throw new InvalidSharePayloadException('mediaId cannot be empty.');
        }

        if (! in_array($mediaId, $this->mediaIds, true))
        {
            $this->mediaIds[] = $mediaId;
        }

        return $this;
    }

    /**
     * @param list<string> $mediaIds
     */
    public function mediaIds(array $mediaIds): self
    {
        foreach ($mediaIds as $mediumId)
        {
            $this->mediaId($mediumId);
        }

        return $this;
    }

    public function media(string $source, ?string $mediaType = null): self
    {
        $source = mb_trim($source);

        if ($source === '')
        {
            throw new InvalidSharePayloadException('media source cannot be empty.');
        }

        $entry = [
            'source' => $source,
        ];

        if ($mediaType !== null)
        {
            $mediaType = mb_strtolower(mb_trim($mediaType));

            if ($mediaType === '')
            {
                throw new InvalidSharePayloadException('media type cannot be empty when provided.');
            }

            $entry['type'] = $mediaType;
        }

        $this->appendMediaSourceForPayload($entry['source'], $entry['type'] ?? null);

        $this->providerOptions['media_sources'] = $this->mediaSources;

        return $this;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = array_replace($this->metadata, $metadata);

        return $this;
    }

    public function option(string $key, mixed $value): self
    {
        if (! $this->isAllowedOptionKey($key))
        {
            throw InvalidSharePayloadException::unsupportedOptionKey($key, $this->provider->value);
        }

        $this->providerOptions[$key] = $value;

        if ($key === 'media_sources' && is_array($value))
        {
            $this->seedMediaSourcesFromOption($value);
            $this->providerOptions['media_sources'] = $this->mediaSources;
        }

        return $this;
    }

    public function published(bool $published = true): self
    {
        $this->ensureProvider(Provider::Facebook);

        return $this->option('published', $published);
    }

    public function scheduledAt(string|int|DateTimeInterface $dateTime): self
    {
        $this->ensureProvider(Provider::Facebook);

        $timestamp = match (true)
        {
            $dateTime instanceof DateTimeInterface => $dateTime->getTimestamp(),
            is_int($dateTime)                      => $dateTime,
            default                                => Carbon::parse($dateTime)->timestamp,
        };

        return $this->option('scheduled_at', $timestamp)->published(false);
    }

    /**
     * @param array<string, mixed> $targeting
     */
    public function targeting(array $targeting): self
    {
        $this->ensureProvider(Provider::Facebook);

        return $this->option('targeting', $targeting);
    }

    /**
     * @param list<string> $items
     */
    public function carousel(array $items): self
    {
        $this->ensureProvider(Provider::Instagram);

        return $this->option('carousel_items', $items);
    }

    public function altText(string $altText): self
    {
        $this->ensureProvider(Provider::Instagram);

        $altText = mb_trim($altText);

        if ($altText === '')
        {
            throw new InvalidSharePayloadException('altText cannot be empty.');
        }

        return $this->option('alt_text', $altText);
    }

    public function reel(): self
    {
        $this->ensureProvider(Provider::Instagram);

        return $this->option('media_type', 'REELS');
    }

    public function replyTo(string $postId): self
    {
        $this->ensureProvider(Provider::Twitter);

        $postId = mb_trim($postId);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('replyTo post id cannot be empty.');
        }

        return $this->option('reply_to', $postId);
    }

    public function quote(string $postId): self
    {
        $this->ensureProvider(Provider::Twitter);

        $postId = mb_trim($postId);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('quote post id cannot be empty.');
        }

        return $this->option('quote_tweet_id', $postId);
    }

    /**
     * @param list<string> $options
     */
    public function poll(array $options, int $durationMinutes): self
    {
        $this->ensureProvider(Provider::Twitter);

        if (count($options) < 2 || count($options) > 4)
        {
            throw new InvalidSharePayloadException('X poll options must contain between 2 and 4 choices.');
        }

        if ($durationMinutes < 5 || $durationMinutes > 10080)
        {
            throw new InvalidSharePayloadException('X poll duration must be between 5 and 10080 minutes.');
        }

        return $this->option('poll', [
            'options'          => $options,
            'duration_minutes' => $durationMinutes,
        ]);
    }

    public function visibility(string $visibility): self
    {
        $this->ensureProvider(Provider::LinkedIn);

        $visibility = mb_strtoupper(mb_trim($visibility));

        if ($visibility === '')
        {
            throw new InvalidSharePayloadException('LinkedIn visibility cannot be empty.');
        }

        return $this->option('visibility', $visibility);
    }

    public function distribution(string $distribution): self
    {
        $this->ensureProvider(Provider::LinkedIn);

        $distribution = mb_strtoupper(mb_trim($distribution));

        if ($distribution === '')
        {
            throw new InvalidSharePayloadException('LinkedIn distribution cannot be empty.');
        }

        return $this->option('distribution', $distribution);
    }

    public function mediaUrn(string $mediaUrn): self
    {
        $this->ensureProvider(Provider::LinkedIn);

        $mediaUrn = mb_trim($mediaUrn);

        if ($mediaUrn === '')
        {
            throw new InvalidSharePayloadException('LinkedIn media URN cannot be empty.');
        }

        return $this->option('media_urn', $mediaUrn);
    }

    public function share(): ShareResult
    {
        $providerOptions                  = $this->providerOptions;
        $providerOptions['media_sources'] = $this->mediaSources;

        return $this->providerDriver->share(new SharePayload(
            message: $this->message,
            link: $this->link,
            imageUrl: $this->imageUrl,
            videoUrl: $this->videoUrl,
            mediaIds: $this->mediaIds,
            providerOptions: $providerOptions,
            metadata: $this->metadata,
            mediaSources: $providerOptions['media_sources'],
        ));
    }

    public function commentOn(string $postId, string $message): CommentResult
    {
        $postId = mb_trim($postId);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('commentOn post id cannot be empty.');
        }

        $message = mb_trim($message);

        if ($message === '')
        {
            throw new InvalidSharePayloadException('commentOn message cannot be empty.');
        }

        return $this->providerDriver->comment($postId, $message);
    }

    public function shareAndComment(string $message): CommentResult
    {
        $shareResult = $this->share();

        return $this->commentOn($shareResult->id(), $message);
    }

    public function delete(string $postId): bool
    {
        return $this->providerDriver->delete($postId);
    }

    public function provider(): Provider
    {
        return $this->provider;
    }

    private function ensureProvider(Provider ...$providers): void
    {
        if (in_array($this->provider, $providers, true))
        {
            return;
        }

        $allowed = implode(', ', array_map(static fn (Provider $provider): string => $provider->value, $providers));

        throw UnsupportedFeatureException::forProviderMethod($allowed, $this->provider->value);
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null)
        {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }

    private function isAllowedOptionKey(string $key): bool
    {
        if (! $this->strictOptionKeys)
        {
            return true;
        }

        if (in_array($key, self::SHARED_OPTION_KEYS, true))
        {
            return true;
        }

        $providerOptionKeys = self::PROVIDER_OPTION_KEYS[$this->provider->value];

        return in_array($key, $providerOptionKeys, true);
    }

    private function appendMediaSourceForPayload(string $source, ?string $type): void
    {
        foreach ($this->mediaSources as $mediumSource)
        {
            if ($mediumSource['source'] === $source && $mediumSource['type'] === $type)
            {
                return;
            }
        }

        $this->mediaSources[] = [
            'source' => $source,
            'type'   => $type,
        ];
    }

    /**
     * @param array<mixed, mixed> $sources
     */
    private function seedMediaSourcesFromOption(array $sources): void
    {
        foreach ($sources as $source)
        {
            if (! is_array($source))
            {
                continue;
            }

            $rawSource = $source['source'] ?? null;
            $rawType   = $source['type']   ?? null;

            if (! is_string($rawSource))
            {
                continue;
            }

            $normalizedSource = mb_trim($rawSource);

            if ($normalizedSource === '')
            {
                continue;
            }

            $normalizedType = is_string($rawType) && mb_trim($rawType) !== ''
                ? mb_strtolower(mb_trim($rawType))
                : null;

            $this->appendMediaSourceForPayload($normalizedSource, $normalizedType);
        }
    }
}
