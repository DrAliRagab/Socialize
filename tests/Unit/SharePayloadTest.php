<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;

it('exposes payload values and options', function (): void {
    $payload = new SharePayload(
        message: 'Hello',
        link: 'https://example.com',
        imageUrl: null,
        videoUrl: null,
        mediaIds: ['11', '22'],
        providerOptions: ['published' => true],
        metadata: ['source' => 'test'],
    );

    expect($payload->message())->toBe('Hello')
        ->and($payload->link())->toBe('https://example.com')
        ->and($payload->mediaIds())->toBe(['11', '22'])
        ->and($payload->mediaSources())->toBe([])
        ->and($payload->providerOptions())->toBe(['published' => true])
        ->and($payload->metadata())->toBe(['source' => 'test'])
        ->and($payload->option('published'))->toBeTrue()
        ->and($payload->option('unknown', 'fallback'))->toBe('fallback')
        ->and($payload->hasAnyCoreContent())->toBeTrue()
    ;
});

it('detects empty core content', function (): void {
    $payload = new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
    );

    expect($payload->hasAnyCoreContent())->toBeFalse();
});

it('does not treat provider option media sources as core content', function (): void {
    $payload = new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [
            'media_sources' => [
                [
                    'source' => '/tmp/image.jpg',
                    'type'   => 'image',
                ],
            ],
        ],
        metadata: [],
    );

    expect($payload->hasAnyCoreContent())->toBeFalse();
});

it('treats payload media sources as core content', function (): void {
    $payload = new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
        mediaSources: [
            [
                'source' => '/tmp/image.jpg',
                'type'   => 'image',
            ],
        ],
    );

    expect($payload->hasAnyCoreContent())->toBeTrue();
});

it('normalizes payload strings and deduplicates media ids and media sources', function (): void {
    $payload = new SharePayload(
        message: '  Hello  ',
        link: ' https://example.com ',
        imageUrl: ' https://cdn.example.com/image.jpg ',
        videoUrl: null,
        mediaIds: ['  11 ', '11', '22'],
        providerOptions: [],
        metadata: [],
        mediaSources: [
            ['source' => ' https://cdn.example.com/media.jpg ', 'type' => 'IMAGE'],
            ['source' => 'https://cdn.example.com/media.jpg', 'type' => 'image'],
        ],
    );

    expect($payload->message())->toBe('Hello')
        ->and($payload->link())->toBe('https://example.com')
        ->and($payload->imageUrl())->toBe('https://cdn.example.com/image.jpg')
        ->and($payload->mediaIds())->toBe(['11', '22'])
        ->and($payload->mediaSources())->toBe([
            ['source' => 'https://cdn.example.com/media.jpg', 'type' => 'image'],
        ])
    ;
});

it('throws when payload contains invalid media ids or media source entries', function (): void {
    expect(fn (): SharePayload => new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [123],
        providerOptions: [],
        metadata: [],
    ))->toThrow(InvalidSharePayloadException::class, 'mediaIds entries must be strings');

    expect(fn (): SharePayload => new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: ['valid', '   '],
        providerOptions: [],
        metadata: [],
    ))->toThrow(InvalidSharePayloadException::class, 'mediaIds entries cannot be empty');

    expect(fn (): SharePayload => new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
        mediaSources: [
            'not-an-array',
        ],
    ))->toThrow(InvalidSharePayloadException::class, 'mediaSources entries must be arrays');

    expect(fn (): SharePayload => new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
        mediaSources: [
            ['source' => 100, 'type' => 'image'],
        ],
    ))->toThrow(InvalidSharePayloadException::class, 'mediaSources source must be a string');

    expect(fn (): SharePayload => new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
        mediaSources: [
            ['source' => ' ', 'type' => 'image'],
        ],
    ))->toThrow(InvalidSharePayloadException::class, 'mediaSources source cannot be empty');

    expect(fn (): SharePayload => new SharePayload(
        message: null,
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
        mediaSources: [
            ['source' => 'https://cdn.example.com/a.jpg', 'type' => ['bad']],
        ],
    ))->toThrow(InvalidSharePayloadException::class, 'mediaSources type must be null or string');
});
