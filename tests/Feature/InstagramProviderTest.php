<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Facades\Socialize;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('shares instagram image content', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-post-1'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('New image')
        ->imageUrl('https://cdn.example.com/ig.jpg')
        ->altText('Product hero image')
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-post-1')
        ->and($shareResult->raw()['container_id'])->toBe('container-1')
    ;
});

it('shares instagram reels video', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-video'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-video-1'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Reel')
        ->videoUrl('https://cdn.example.com/reel.mp4')
        ->reel()
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-video-1');

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/media') || str_contains($request->url(), '/media_publish'))
        {
            return false;
        }

        return ($request->data()['media_type'] ?? null) === 'REELS';
    });
});

it('shares instagram carousel content', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['id' => 'parent-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['id' => 'ig-carousel-1'], 200),
    ]);

    $shareResult = Socialize::instagram()
        ->message('Carousel')
        ->carousel([
            'https://cdn.example.com/1.jpg',
            'https://cdn.example.com/2.jpg',
        ])
        ->share()
    ;

    expect($shareResult->id())->toBe('ig-carousel-1');

    Http::assertSentCount(4);
});

it('deletes instagram post', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::response(['success' => true], 200),
    ]);

    expect(Socialize::instagram()->delete('179123'))->toBeTrue();
});

it('throws for empty instagram post id on delete', function (): void {
    Http::fake();

    Socialize::instagram()->delete('   ');
})->throws(InvalidSharePayloadException::class, 'Instagram post id cannot be empty');

it('fails instagram share with invalid url', function (): void {
    Http::fake();

    Socialize::instagram()->imageUrl('bad-url')->share();
})->throws(InvalidSharePayloadException::class, 'must be a valid URL');

it('fails instagram share without media', function (): void {
    Http::fake();

    Socialize::instagram()->message('Only text')->share();
})->throws(InvalidSharePayloadException::class, 'requires imageUrl or videoUrl when carousel is not used');

it('fails instagram share with empty payload', function (): void {
    Http::fake();

    Socialize::instagram()->share();
})->throws(InvalidSharePayloadException::class, 'requires imageUrl, videoUrl, or carousel items');

it('fails instagram share when required credentials are missing', function (): void {
    Http::fake();
    config()->set('socialize.providers.instagram.profiles.default.access_token');

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(InvalidConfigException::class, 'Missing required credential [access_token]');

it('fails instagram with invalid media type option', function (): void {
    Http::fake();

    Socialize::instagram()
        ->videoUrl('https://cdn.example.com/clip.mp4')
        ->option('media_type', 'podcast')
        ->share()
    ;
})->throws(InvalidSharePayloadException::class, 'must be one of VIDEO, REELS, STORIES');

it('throws api exception when instagram publish does not return id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['ok' => true], 200),
        'https://graph.facebook.com/v25.0/container-1*'        => Http::response(['status_code' => 'ERROR', 'status' => 'IN_PROGRESS'], 200),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(ApiException::class, 'did not return a media id');

it('throws api exception when instagram publish status query has no status details', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media'         => Http::response(['id' => 'container-1'], 200),
        'https://graph.facebook.com/v25.0/98765/media_publish' => Http::response(['ok' => true], 200),
        'https://graph.facebook.com/v25.0/container-1*'        => Http::response([], 200),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(ApiException::class, 'Instagram API did not return a media id after publishing.');

it('throws when instagram container creation response has no id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::response(['ok' => true], 200),
    ]);

    Socialize::instagram()->imageUrl('https://cdn.example.com/ig.jpg')->share();
})->throws(ApiException::class, 'did not return a container id');

it('throws when instagram carousel child container response has no id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::response(['ok' => true], 200),
    ]);

    Socialize::instagram()
        ->message('Carousel')
        ->carousel([
            'https://cdn.example.com/1.jpg',
            'https://cdn.example.com/2.jpg',
        ])
        ->share()
    ;
})->throws(ApiException::class, 'did not return a child container id');

it('throws when instagram carousel parent container response has no id', function (): void {
    Http::fake([
        'https://graph.facebook.com/v25.0/98765/media' => Http::sequence()
            ->push(['id' => 'child-1'], 200)
            ->push(['id' => 'child-2'], 200)
            ->push(['ok' => true], 200),
    ]);

    Socialize::instagram()
        ->message('Carousel')
        ->carousel([
            'https://cdn.example.com/1.jpg',
            'https://cdn.example.com/2.jpg',
        ])
        ->share()
    ;
})->throws(ApiException::class, 'did not return a parent carousel container id');
