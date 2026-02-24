<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\ValueObjects;

final readonly class SharePayload
{
    /**
     * @param list<string> $mediaIds
     * @param array<string, mixed> $providerOptions
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private ?string $message,
        private ?string $link,
        private ?string $imageUrl,
        private ?string $videoUrl,
        private array $mediaIds,
        private array $providerOptions,
        private array $metadata,
    ) {}

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
            || $this->mediaIds !== [];
    }
}
