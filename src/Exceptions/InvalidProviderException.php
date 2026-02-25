<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Exceptions;

use function sprintf;

final class InvalidProviderException extends SocializeException
{
    public static function unsupported(string $provider): self
    {
        return new self(sprintf('Unsupported provider [%s].', $provider));
    }
}
