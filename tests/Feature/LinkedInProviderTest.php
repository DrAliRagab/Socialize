<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
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

it('deletes linkedin post when API returns success field', function (): void {
    Http::fake([
        'https://api.linkedin.com/rest/posts/*' => Http::response(['success' => true], 200),
    ]);

    expect(Socialize::linkedin()->delete('urn:li:share:2'))->toBeTrue();
});

it('throws for empty linkedin post id on delete', function (): void {
    Http::fake();

    Socialize::linkedin()->delete('   ');
})->throws(InvalidSharePayloadException::class, 'LinkedIn post id cannot be empty');

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

it('throws when linkedin version format is invalid', function (): void {
    Http::fake();
    config()->set('socialize.providers.linkedin.profiles.default.version', '2026-02');

    Socialize::linkedin()->message('Version test')->share();
})->throws(InvalidConfigException::class, 'LinkedIn version must use YYYYMM format');

it('uses default linkedin version header when version is not configured', function (): void {
    config()->set('socialize.providers.linkedin.profiles.default.version');

    Http::fake([
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:default-version'], 201),
    ]);

    $shareResult = Socialize::linkedin()->message('Default version')->share();

    expect($shareResult->id())->toBe('urn:li:share:default-version');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Linkedin-Version', '202602'));
});

it('throws when linkedin required credentials are missing', function (): void {
    Http::fake();
    config()->set('socialize.providers.linkedin.profiles.default.access_token');

    Socialize::linkedin()->message('Missing token')->share();
})->throws(InvalidConfigException::class, 'Missing required credential [access_token]');
