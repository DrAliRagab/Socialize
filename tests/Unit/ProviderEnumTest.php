<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;

it('resolves provider aliases correctly', function (): void {
    expect(Provider::fromString('fb'))->toBe(Provider::Facebook)
        ->and(Provider::fromString('ig'))->toBe(Provider::Instagram)
        ->and(Provider::fromString('x'))->toBe(Provider::Twitter)
        ->and(Provider::fromString('li'))->toBe(Provider::LinkedIn)
    ;
});

it('throws for unsupported provider', function (): void {
    Provider::fromString('myspace');
})->throws(InvalidArgumentException::class);
