<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\SocializeManager;
use DrAliRagab\Socialize\Support\FluentShare;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

it('falls back to default profile when profile argument is blank', function (): void {
    $fluentShare = app(SocializeManager::class)->facebook('   ');

    expect($fluentShare)->toBeInstanceOf(FluentShare::class);
});

it('falls back to literal default profile when configured defaults are blank', function (): void {
    config()->set('socialize.default_profile', '   ');
    config()->set('socialize.providers.facebook.default_profile', '   ');

    $fluentShare = app(SocializeManager::class)->facebook('   ');

    expect($fluentShare)->toBeInstanceOf(FluentShare::class);
});

it('registers socialize manager as singleton and alias', function (): void {
    expect(app(SocializeManager::class))->toBe(app(SocializeManager::class))
        ->and(app('socialize'))->toBe(app(SocializeManager::class))
    ;
});

it('throws when configured driver class is invalid', function (): void {
    config()->set('socialize.drivers.facebook', stdClass::class);

    app(SocializeManager::class)->facebook();
})->throws(InvalidConfigException::class, 'is invalid for provider [facebook]');

it('falls back to default driver when configured driver class is blank', function (): void {
    config()->set('socialize.drivers.facebook', '   ');

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-default-driver'], 200),
    ]);

    $shareResult = app(SocializeManager::class)
        ->facebook()
        ->message('Default driver fallback')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-default-driver');
});

it('allows unknown option keys when strict options are disabled', function (): void {
    config()->set('socialize.strict_option_keys', false);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-loose-option'], 200),
    ]);

    $shareResult = app(SocializeManager::class)
        ->facebook()
        ->message('Loose option')
        ->option('future_option_key', 'value')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-loose-option');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/12345/feed');
});
