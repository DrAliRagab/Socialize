<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Exceptions\UnsupportedFeatureException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('rejects provider-specific method on wrong provider', function (): void {
    Socialize::facebook()->reel();
})->throws(UnsupportedFeatureException::class, 'only available for [instagram]');

it('validates poll option count and duration', function (): void {
    Socialize::twitter()->poll(['only-one'], 10);
})->throws(InvalidSharePayloadException::class, 'between 2 and 4');

it('supports scheduledAt for facebook and sets publish flags', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-scheduled'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Scheduled post')
        ->scheduledAt('2026-02-25 10:00:00')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-scheduled');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url()              === 'https://graph.facebook.com/v25.0/12345/feed'
            && $request->method()           === 'POST'
            && ($data['published'] ?? null) === false
            && isset($data['scheduled_publish_time']);
    });
});

it('normalizes media ids and rejects empty media id', function (): void {
    Socialize::twitter()->mediaId('');
})->throws(InvalidSharePayloadException::class, 'mediaId cannot be empty');
