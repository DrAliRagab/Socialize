<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Tests\TestCase;
use Illuminate\Support\Facades\Http;

pest()
    ->extend(TestCase::class)
    ->in('Feature', 'Unit')
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
    })
;
