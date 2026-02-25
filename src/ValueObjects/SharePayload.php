<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\ValueObjects;

use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;

use function in_array;
use function is_array;
use function is_string;

final readonly class SharePayload
{
    private ?string $message;

    private ?string $link;

    private ?string $imageUrl;

    private ?string $videoUrl;

    /** @var list<string> */
    private array $mediaIds;

    /** @var list<array{source: string, type: ?string}> */
    private array $mediaSources;

    /**
     * @param array<mixed, mixed> $mediaIds
     * @param array<mixed, mixed> $mediaSources
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        ?string $message,
        ?string $link,
        ?string $imageUrl,
        ?string $videoUrl,
        array $mediaIds,
        private array $providerOptions,
        private array $metadata,
        array $mediaSources = [],
    ) {
        $this->message      = $this->normalizeNullableString($message);
        $this->link         = $this->normalizeNullableString($link);
        $this->imageUrl     = $this->normalizeNullableString($imageUrl);
        $this->videoUrl     = $this->normalizeNullableString($videoUrl);
        $this->mediaIds     = $this->normalizeMediaIds($mediaIds);
        $this->mediaSources = $this->normalizeMediaSources($mediaSources);
    }

    public function message(): ?string
    {
        return $this->message;
    }

    public function link(): ?string
    {
        return $this->link;
    }

    public function imageUrl(): ?string
    {
        return $this->imageUrl;
    }

    public function videoUrl(): ?string
    {
        return $this->videoUrl;
    }

    /**
     * @return list<string>
     */
    public function mediaIds(): array
    {
        return $this->mediaIds;
    }

    /**
     * @return list<array{source: string, type: ?string}>
     */
    public function mediaSources(): array
    {
        return $this->mediaSources;
    }

    /**
     * @return array<string, mixed>
     */
    public function providerOptions(): array
    {
        return $this->providerOptions;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->providerOptions[$key] ?? $default;
    }

    public function hasAnyCoreContent(): bool
    {
        return ($this->message !== null && $this->message !== '')
            || ($this->link !== null && $this->link !== '')
            || ($this->imageUrl !== null && $this->imageUrl !== '')
            || ($this->videoUrl !== null && $this->videoUrl !== '')
            || $this->mediaIds     !== []
            || $this->mediaSources !== [];
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

    /**
     * @param array<mixed, mixed> $mediaIds
     * @return list<string>
     */
    private function normalizeMediaIds(array $mediaIds): array
    {
        $normalized = [];

        foreach ($mediaIds as $mediumId)
        {
            if (! is_string($mediumId))
            {
                throw new InvalidSharePayloadException('Share payload mediaIds entries must be strings.');
            }

            $mediumId = mb_trim($mediumId);

            if ($mediumId === '')
            {
                throw new InvalidSharePayloadException('Share payload mediaIds entries cannot be empty.');
            }

            if (! in_array($mediumId, $normalized, true))
            {
                $normalized[] = $mediumId;
            }
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $mediaSources
     * @return list<array{source: string, type: ?string}>
     */
    private function normalizeMediaSources(array $mediaSources): array
    {
        $normalized = [];

        foreach ($mediaSources as $mediumSource)
        {
            if (! is_array($mediumSource))
            {
                throw new InvalidSharePayloadException('Share payload mediaSources entries must be arrays.');
            }

            $source = $mediumSource['source'] ?? null;
            $type   = $mediumSource['type']   ?? null;

            if (! is_string($source))
            {
                throw new InvalidSharePayloadException('Share payload mediaSources source must be a string.');
            }

            $source = mb_trim($source);

            if ($source === '')
            {
                throw new InvalidSharePayloadException('Share payload mediaSources source cannot be empty.');
            }

            if ($type !== null && ! is_string($type))
            {
                throw new InvalidSharePayloadException('Share payload mediaSources type must be null or string.');
            }

            $normalizedType = is_string($type) ? mb_strtolower(mb_trim($type)) : null;
            $normalizedType = $normalizedType === '' ? null : $normalizedType;
            $exists         = array_any($normalized, fn (array $entry): bool => $entry['source'] === $source && $entry['type'] === $normalizedType);

            if (! $exists)
            {
                $normalized[] = [
                    'source' => $source,
                    'type'   => $normalizedType,
                ];
            }
        }

        return $normalized;
    }
}
