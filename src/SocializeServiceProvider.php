<?php

namespace DrAliRagab\Socialize;

use DrAliRagab\Socialize\Commands\SocializeCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SocializeServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('socialize')
            ->hasConfigFile();
        // ->hasViews()
        // ->hasMigration('create_socialize_table')
        // ->hasCommand(SocializeCommand::class);
    }
}
