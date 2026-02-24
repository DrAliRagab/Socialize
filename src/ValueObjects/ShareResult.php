<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\ValueObjects;

use DrAliRagab\Socialize\Enums\Provider;

final readonly class ShareResult
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        private Provider $provider,
        private string $id,
        private ?string $url,
        private array $raw = [],
    ) {}

    public function provider(): Provider
    {
        return $this->provider;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function url(): ?string
    {
        return $this->url;
    }

    /**
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider->value,
            'id'       => $this->id,
            'url'      => $this->url,
            'raw'      => $this->raw,
        ];
    }
}
