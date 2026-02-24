<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('shares a linkedin post with commentary and link', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:1'], 201),
    ]);

    $shareResult = Socialize::linkedin()
        ->message('Professional update')
        ->link('https://example.com/update')
        ->visibility('public')
        ->distribution('main_feed')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:1');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Linkedin-Version', '202602')
        && $request->hasHeader('X-Restli-Protocol-Version', '2.0.0')
        && ($request->data()['author'] ?? null) === 'urn:li:person:123');
});

it('shares linkedin post with media urn only', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response([], 201, ['x-restli-id' => 'urn:li:share:2']),
    ]);

    $shareResult = Socialize::linkedin()
        ->mediaUrn('urn:li:image:abc')
        ->share()
    ;

    expect($shareResult->id())->toBe('urn:li:share:2');
});

it('deletes linkedin post', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts/*' => Http::response('', 204),
    ]);

    expect(Socialize::linkedin()->delete('urn:li:share:2'))->toBeTrue();
});

it('fails linkedin with invalid link', function (): void {
    Http::fake();

    Socialize::linkedin()->message('bad')->link('bad-url')->share();
})->throws(InvalidSharePayloadException::class, 'must be a valid URL');

it('fails linkedin when content is empty', function (): void {
    Http::fake();

    Socialize::linkedin()->share();
})->throws(InvalidSharePayloadException::class, 'requires text/link content or a media URN');

it('throws api exception for linkedin failures', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['message' => 'forbidden'], 403),
    ]);

    Socialize::linkedin()->message('Nope')->share();
})->throws(ApiException::class, 'status 403');

it('throws when linkedin response has no id', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['ok' => true], 201),
    ]);

    Socialize::linkedin()->message('No id')->share();
})->throws(ApiException::class, 'did not return a post id');
