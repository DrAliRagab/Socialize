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
        'https://cdn.example.com/dedupe.jpg'               => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://upload.twitter.com/1.1/media/upload.json' => Http::sequence()
            ->push(['media_id_string' => 'dedupe-media'], 200)
            ->push('', 204)
            ->push(['media_id_string' => 'dedupe-media'], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'dedupe-post']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->option('media_sources', 'invalid-entry')
        ->media('https://cdn.example.com/dedupe.jpg', 'image')
        ->media('https://cdn.example.com/dedupe.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('dedupe-post');

    Http::assertSentCount(5);
});

it('ignores non-array seeded media_sources entries while deduplicating fluent media entries', function (): void {
    Http::fake([
        'https://cdn.example.com/non-array-seeded.jpg'     => Http::response('image-binary', 200, ['Content-Type' => 'image/jpeg']),
        'https://upload.twitter.com/1.1/media/upload.json' => Http::sequence()
            ->push(['media_id_string' => 'media-non-array-seeded'], 200)
            ->push('', 204)
            ->push(['media_id_string' => 'media-non-array-seeded'], 200),
        'https://api.x.com/2/tweets' => Http::response(['data' => ['id' => 'post-non-array-seeded']], 200),
    ]);

    $shareResult = Socialize::twitter()
        ->option('media_sources', ['bad-entry', ['source' => 'https://cdn.example.com/non-array-seeded.jpg', 'type' => 'image']])
        ->media('https://cdn.example.com/non-array-seeded.jpg', 'image')
        ->share()
    ;

    expect($shareResult->id())->toBe('post-non-array-seeded');

    Http::assertSentCount(5);
});
