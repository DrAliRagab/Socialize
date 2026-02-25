<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Exceptions;

use function sprintf;

final class UnsupportedFeatureException extends SocializeException
{
    public static function forProviderMethod(string $allowedProviders, string $currentProvider): self
    {
        return new self(sprintf('This method is only available for [%s], current provider is [%s].', $allowedProviders, $currentProvider));
    }

    public static function forProviderPayloadField(string $field, string $provider): self
    {
        return new self(sprintf('Payload field [%s] is not supported by provider [%s].', $field, $provider));
    }
}
