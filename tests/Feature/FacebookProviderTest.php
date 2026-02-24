<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('shares a facebook feed post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-1'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Release note')
        ->link('https://example.com/release')
        ->published(true)
        ->targeting(['geo_locations' => ['countries' => ['US']]])
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-1')
        ->and($shareResult->provider()->value)->toBe('facebook')
    ;

    Http::assertSent(
        fn (Request $request): bool => $request->url() === 'https://graph.facebook.com/v25.0/12345/feed'
        && $request->method()                          === 'POST'
        && ($request->data()['link'] ?? null)          === 'https://example.com/release'
    );
});

it('shares a facebook photo post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['post_id' => 'fb-photo'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Photo post')
        ->imageUrl('https://cdn.example.com/pic.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-photo');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/photos'));
});

it('shares a facebook photo post without caption parts', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['post_id' => 'fb-photo-no-caption'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->imageUrl('https://cdn.example.com/pic.jpg')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-photo-no-caption');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return str_contains($request->url(), '/photos')
            && \array_key_exists('caption', $data)
            && $data['caption'] === null;
    });
});

it('shares a facebook video post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-video'], 200),
    ]);

    $shareResult = Socialize::facebook()
        ->message('Video post')
        ->videoUrl('https://cdn.example.com/video.mp4')
        ->share()
    ;

    expect($shareResult->id())->toBe('fb-video');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos'));
});

it('deletes a facebook post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    expect(Socialize::facebook()->delete('123_456'))->toBeTrue();
});

it('throws for empty facebook post id on delete', function (): void {
    Http::fake();

    Socialize::facebook()->delete('   ');
})->throws(InvalidSharePayloadException::class, 'Facebook post id cannot be empty');

it('throws for invalid facebook link url', function (): void {
    Http::fake();

    Socialize::facebook()
        ->message('Invalid link')
        ->link('not-a-url')
        ->share()
    ;
})->throws(InvalidSharePayloadException::class, 'must be a valid URL');

it('throws for missing facebook content', function (): void {
    Http::fake();

    Socialize::facebook()->share();
})->throws(InvalidSharePayloadException::class, 'requires at least one');

it('throws when facebook credentials are missing in selected profile', function (): void {
    Http::fake();

    Socialize::facebook('missing-token')
        ->message('Hello')
        ->share()
    ;
})->throws(InvalidConfigException::class, 'Missing required credential [access_token]');

it('throws api exception on facebook failure', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['error' => ['message' => 'Bad token']], 401),
    ]);

    Socialize::facebook()->message('Hello')->share();
})->throws(ApiException::class, 'Facebook API request failed with status 401');

it('throws for facebook invalid response body missing id', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['ok' => true], 200),
    ]);

    Socialize::facebook()->message('Hello')->share();
})->throws(ApiException::class, 'did not return a post id');

it('falls back to default facebook base url when base_url config is invalid', function (): void {
    config()->set('socialize.providers.facebook.base_url', ['invalid']);

    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['id' => 'fb-default-base'], 200),
    ]);

    $shareResult = Socialize::facebook()->message('Fallback base URL')->share();

    expect($shareResult->id())->toBe('fb-default-base');
});
