<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Enums;

use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;

enum Provider: string
{
    case Facebook  = 'facebook';
    case Instagram = 'instagram';
    case Twitter   = 'twitter';
    case LinkedIn  = 'linkedin';

    public static function fromString(string $provider): self
    {
        $normalized = mb_strtolower(mb_trim($provider));

        return match ($normalized)
        {
            'facebook', 'fb' => self::Facebook,
            'instagram', 'ig' => self::Instagram,
            'twitter', 'x' => self::Twitter,
            'linkedin', 'li' => self::LinkedIn,
            default => throw InvalidSharePayloadException::unsupportedProvider($provider),
        };
    }
}
