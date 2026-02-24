<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

it('converts share result to array', function (): void {
    $result = new ShareResult(Provider::Twitter, '100', 'https://x.com/i/web/status/100', ['ok' => true]);

    expect($result->toArray())->toBe([
        'provider' => 'twitter',
        'id'       => '100',
        'url'      => 'https://x.com/i/web/status/100',
        'raw'      => ['ok' => true],
    ]);
});
