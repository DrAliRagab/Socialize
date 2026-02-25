<?php

declare(strict_types=1);

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

it('treats provider media sources as core content', function (): void {
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

    expect($payload->hasAnyCoreContent())->toBeTrue();
});
