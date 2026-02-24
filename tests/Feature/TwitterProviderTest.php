<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Exceptions\UnsupportedFeatureException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('shares a text post on x', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-1']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->message('Hello X')
        ->link('https://example.com')
        ->share()
    ;

    expect($shareResult->id())->toBe('x-1')
        ->and($shareResult->url())->toBe('https://x.com/i/web/status/x-1')
    ;
});

it('shares a media x post with reply quote and poll', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-2']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->message('Vote now')
        ->mediaIds(['1', '2'])
        ->replyTo('44')
        ->quote('55')
        ->poll(['A', 'B'], 30)
        ->share()
    ;

    expect($shareResult->id())->toBe('x-2');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return ($data['reply']['in_reply_to_tweet_id'] ?? null) === '44'
            && ($data['quote_tweet_id'] ?? null)                === '55'
            && ($data['poll']['duration_minutes'] ?? null)      === 30
            && ($data['media']['media_ids'] ?? [])              === ['1', '2'];
    });
});

it('deletes x post', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets/*' => Http::response(['data' => ['deleted' => true]], 200),
    ]);

    expect(Socialize::twitter()->delete('1234'))->toBeTrue();
});

it('fails x share when image url is used without media ids', function (): void {
    Http::fake();

    Socialize::twitter()->imageUrl('https://cdn.example.com/image.jpg')->share();
})->throws(UnsupportedFeatureException::class, 'media upload should be done before sharing');

it('fails x share when link is invalid', function (): void {
    Http::fake();

    Socialize::twitter()->message('hello')->link('bad-link')->share();
})->throws(InvalidSharePayloadException::class, 'must be a valid URL');

it('fails x share when resolved body is empty', function (): void {
    Http::fake();

    Socialize::twitter()->share();
})->throws(InvalidSharePayloadException::class, 'requires text, link, or media ids');

it('throws api exception for x failures', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['title' => 'Unauthorized'], 401),
    ]);

    Socialize::twitter()->message('fail')->share();
})->throws(ApiException::class, 'status 401');

it('throws when x response does not include id', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => []], 200),
    ]);

    Socialize::twitter()->message('missing id')->share();
})->throws(ApiException::class, 'did not return a post id');
