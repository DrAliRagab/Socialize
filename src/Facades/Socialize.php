<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \DrAliRagab\Socialize\Support\FluentShare provider(\DrAliRagab\Socialize\Enums\Provider|string $provider, ?string $profile = null)
 * @method static \DrAliRagab\Socialize\Support\FluentShare facebook(?string $profile = null)
 * @method static \DrAliRagab\Socialize\Support\FluentShare instagram(?string $profile = null)
 * @method static \DrAliRagab\Socialize\Support\FluentShare twitter(?string $profile = null)
 * @method static \DrAliRagab\Socialize\Support\FluentShare linkedin(?string $profile = null)
 */
final class Socialize extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \DrAliRagab\Socialize\SocializeManager::class;
    }
}
