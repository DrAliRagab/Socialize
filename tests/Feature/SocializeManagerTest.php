<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\SocializeManager;
use DrAliRagab\Socialize\Support\FluentShare;

it('resolves fluent share for each convenience method', function (): void {
    /** @var SocializeManager $socializeManager */
    $socializeManager = app(SocializeManager::class);

    expect($socializeManager->facebook())->toBeInstanceOf(FluentShare::class)
        ->and($socializeManager->instagram())->toBeInstanceOf(FluentShare::class)
        ->and($socializeManager->twitter())->toBeInstanceOf(FluentShare::class)
        ->and($socializeManager->linkedin())->toBeInstanceOf(FluentShare::class)
        ->and($socializeManager->provider('x')->provider())->toBe(Provider::Twitter)
    ;
});

it('throws when provider config is missing', function (): void {
    config()->set('socialize.providers.facebook');

    app(SocializeManager::class)->facebook();
})->throws(InvalidConfigException::class, 'Provider configuration [facebook] is missing');

it('throws when profile is missing', function (): void {
    app(SocializeManager::class)->facebook('not-found');
})->throws(InvalidConfigException::class, 'Profile [not-found] is not configured for provider [facebook].');
