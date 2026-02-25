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

it('rejects targeting and carousel when provider does not support them', function (): void {
    expect(fn (): mixed => Socialize::twitter()->targeting(['geo_locations' => ['countries' => ['US']]]))
        ->toThrow(UnsupportedFeatureException::class, 'only available for [facebook]')
    ;

    expect(fn (): mixed => Socialize::facebook()->carousel(['https://cdn.example.com/1.jpg', 'https://cdn.example.com/2.jpg']))
        ->toThrow(UnsupportedFeatureException::class, 'only available for [instagram]')
    ;
});

it('validates poll option count and duration', function (): void {
    Socialize::twitter()->poll(['only-one'], 10);
})->throws(InvalidSharePayloadException::class, 'between 2 and 4');

it('validates poll maximum duration', function (): void {
    Socialize::twitter()->poll(['A', 'B'], 10081);
})->throws(InvalidSharePayloadException::class, 'between 5 and 10080');

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

it('supports scheduledAt with DateTimeInterface and timestamp integer', function (): void {
    Http::fake([
        'https://graph.facebook.com/*' => Http::sequence()
            ->push(['id' => 'fb-datetime'], 200)
            ->push(['id' => 'fb-int'], 200),
    ]);

    Socialize::facebook()
        ->message('Scheduled datetime')
        ->scheduledAt(new DateTimeImmutable('2026-02-25 11:30:00'))
        ->share()
    ;

    Socialize::facebook()
        ->message('Scheduled int')
        ->scheduledAt(1_772_000_000)
        ->share()
    ;

    Http::assertSent(fn (Request $request): bool => isset($request->data()['scheduled_publish_time']) && $request->data()['scheduled_publish_time'] > 0);
});

it('rejects unsupported strict option keys by default', function (): void {
    Socialize::facebook()->option('scheduleed_at', 1_772_000_000);
})->throws(InvalidSharePayloadException::class, 'Unsupported option key');

it('normalizes media ids and rejects empty media id', function (): void {
    Socialize::twitter()->mediaId('');
})->throws(InvalidSharePayloadException::class, 'mediaId cannot be empty');

it('rejects empty shared media source and media type', function (): void {
    expect(fn (): mixed => Socialize::twitter()->media('   '))
        ->toThrow(InvalidSharePayloadException::class, 'media source cannot be empty')
    ;

    expect(fn (): mixed => Socialize::twitter()->media('/tmp/file.jpg', '   '))
        ->toThrow(InvalidSharePayloadException::class, 'media type cannot be empty')
    ;
});

it('rejects empty provider-specific identifiers', function (): void {
    expect(fn (): mixed => Socialize::instagram()->altText('   '))
        ->toThrow(InvalidSharePayloadException::class, 'altText cannot be empty')
    ;

    expect(fn (): mixed => Socialize::twitter()->replyTo('   '))
        ->toThrow(InvalidSharePayloadException::class, 'replyTo post id cannot be empty')
    ;

    expect(fn (): mixed => Socialize::twitter()->quote('   '))
        ->toThrow(InvalidSharePayloadException::class, 'quote post id cannot be empty')
    ;

    expect(fn (): mixed => Socialize::linkedin()->visibility('   '))
        ->toThrow(InvalidSharePayloadException::class, 'LinkedIn visibility cannot be empty')
    ;

    expect(fn (): mixed => Socialize::linkedin()->distribution('   '))
        ->toThrow(InvalidSharePayloadException::class, 'LinkedIn distribution cannot be empty')
    ;

    expect(fn (): mixed => Socialize::linkedin()->mediaUrn('   '))
        ->toThrow(InvalidSharePayloadException::class, 'LinkedIn media URN cannot be empty')
    ;
});

it('rejects empty link article title when provided', function (): void {
    Socialize::linkedin()->link('https://example.com/article', '   ');
})->throws(InvalidSharePayloadException::class, 'link article title cannot be empty when provided');

it('accepts metadata chaining and ignores blank nullable shared values', function (): void {
    Http::fake([
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'x-metadata']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->message('  ')
        ->link('https://example.com')
        ->imageUrl('   ')
        ->videoUrl('   ')
        ->metadata(['key1' => 'value1'])
        ->metadata(['key2' => 'value2'])
        ->share()
    ;

    expect($shareResult->id())->toBe('x-metadata');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url()         === 'https://api.x.com/2/tweets'
            && ($data['text'] ?? null) === 'https://example.com';
    });
});

it('deduplicates fluent media sources and ignores invalid pre-seeded media source entries', function (): void {
    Http::fake([
        'https://cdn.example.com/dedupe.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*'  => Http::sequence()
            ->push(['data' => ['id' => 'dedupe-media']], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'dedupe-post']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->option('media_sources', 'invalid-entry')
        ->media('https://cdn.example.com/dedupe.jpg', 'image')
        ->media('https://cdn.example.com/dedupe.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('dedupe-post');

    Http::assertSentCount(3);
});

it('ignores non-array seeded media_sources entries while deduplicating fluent media entries', function (): void {
    Http::fake([
        'https://cdn.example.com/non-array-seeded.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*'            => Http::sequence()
            ->push(['data' => ['id' => 'media-non-array-seeded']], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'post-non-array-seeded']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->option('media_sources', ['bad-entry', ['source' => 'https://cdn.example.com/non-array-seeded.jpg', 'type' => 'image']])
        ->media('https://cdn.example.com/non-array-seeded.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('post-non-array-seeded');

    Http::assertSentCount(3);
});

it('ignores seeded media source entries with non-string or blank source values', function (): void {
    Http::fake([
        'https://cdn.example.com/seeded-filter.jpg' => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://api.x.com/2/media/upload*'         => Http::sequence()
            ->push(['data' => ['id' => 'media-seeded-filter']], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'post-seeded-filter']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->option('media_sources', [
            ['source' => 123, 'type' => 'image'],
            ['source' => '   ', 'type' => 'image'],
        ])
        ->media('https://cdn.example.com/seeded-filter.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('post-seeded-filter');

    Http::assertSentCount(3);
});

it('supports sequential sharing across providers in a single flow', function (): void {
    Http::fake([
        'https://graph.facebook.com/*'        => Http::response(['id' => 'fb-seq'], 200),
        'https://api.linkedin.com/rest/posts' => Http::response(['id' => 'urn:li:share:seq'], 201),
    ]);

    $shareResult    = Socialize::facebook()->message('fb')->share();
    $linkedInResult = Socialize::linkedin()->message('li')->share();

    expect($shareResult->id())->toBe('fb-seq')
        ->and($linkedInResult->id())->toBe('urn:li:share:seq')
    ;
});
