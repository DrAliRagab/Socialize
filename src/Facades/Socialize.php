<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Facades;

use DrAliRagab\Socialize\SocializeManager;
use DrAliRagab\Socialize\Support\FluentShare;
use Illuminate\Support\Facades\Facade;

/**
 * @method static FluentShare provider(string $provider, ?string $profile = null)
 * @method static FluentShare facebook(?string $profile = null)
 * @method static FluentShare instagram(?string $profile = null)
 * @method static FluentShare twitter(?string $profile = null)
 * @method static FluentShare linkedin(?string $profile = null)
 */
final class Socialize extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SocializeManager::class;
    }
}
