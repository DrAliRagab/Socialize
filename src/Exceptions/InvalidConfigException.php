<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Exceptions;

use function sprintf;

final class InvalidConfigException extends SocializeException
{
    public static function missingCredential(string $credential, string $provider, string $profile): self
    {
        return new self(
            sprintf('Missing required credential [%s] for provider [%s] and profile [%s].', $credential, $provider, $profile),
        );
    }

    public static function missingProviderConfiguration(string $provider): self
    {
        return new self(sprintf('Provider configuration [%s] is missing.', $provider));
    }

    public static function missingProfile(string $provider, string $profile): self
    {
        return new self(sprintf('Profile [%s] is not configured for provider [%s].', $profile, $provider));
    }

    public static function invalidDriver(string $provider, string $driverClass, string $contract): self
    {
        return new self(
            sprintf('Driver [%s] is invalid for provider [%s]. It must implement [%s].', $driverClass, $provider, $contract),
        );
    }
}
