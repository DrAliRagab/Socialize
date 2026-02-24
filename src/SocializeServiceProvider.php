<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize;

use Illuminate\Contracts\Config\Repository;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

final class SocializeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('socialize')
            ->hasConfigFile()
        ;
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(SocializeManager::class, fn (): SocializeManager => new SocializeManager($this->app->make(Repository::class)));
        $this->app->alias(SocializeManager::class, 'socialize');
    }
}
