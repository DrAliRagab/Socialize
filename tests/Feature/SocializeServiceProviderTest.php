<?php

declare(strict_types=1);

use DrAliRagab\Socialize\SocializeManager;

it('registers socialize bindings in the container', function (): void {
    expect(app()->bound(SocializeManager::class))->toBeTrue()
        ->and(app()->bound('socialize'))->toBeTrue()
        ->and(app(SocializeManager::class))->toBe(app('socialize'))
    ;
});
