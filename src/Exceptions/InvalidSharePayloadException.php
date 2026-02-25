<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Exceptions;

use function sprintf;

final class InvalidSharePayloadException extends SocializeException
{
    public static function unsupportedProvider(string $provider): self
    {
        return new self(sprintf('Unsupported provider [%s].', $provider));
    }

    public static function unsupportedOptionKey(string $key, string $provider): self
    {
        return new self(sprintf('Unsupported option key [%s] for provider [%s].', $key, $provider));
    }
}
