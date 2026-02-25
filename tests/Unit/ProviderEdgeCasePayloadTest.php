<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Providers\LinkedInProvider;
use DrAliRagab\Socialize\Providers\TwitterProvider;
use DrAliRagab\Socialize\ValueObjects\SharePayload;

/**
 * @return array<string, int>
 */
function testHttpConfig(): array
{
    return [
        'timeout'         => 1,
        'connect_timeout' => 1,
        'retries'         => 1,
        'retry_sleep_ms'  => 1,
    ];
}

it('rejects x payloads that collapse to an empty request body', function (): void {
    $provider = new TwitterProvider(
        providerConfig: ['base_url' => 'https://api.x.com'],
        credentials: ['bearer_token' => 'x-token'],
        httpConfig: testHttpConfig(),
        profile: 'default',
    );

    $provider->share(new SharePayload(
        message: '   ',
        link: '   ',
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
    ));
})->throws(InvalidSharePayloadException::class, 'requires text, link, or media ids');

it('rejects linkedin payloads with whitespace-only commentary and no media urn', function (): void {
    $provider = new LinkedInProvider(
        providerConfig: ['base_url' => 'https://api.linkedin.com'],
        credentials: ['author' => 'urn:li:person:123', 'access_token' => 'li-token', 'version' => '202602'],
        httpConfig: testHttpConfig(),
        profile: 'default',
    );

    $provider->share(new SharePayload(
        message: '   ',
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: [],
        metadata: [],
    ));
})->throws(InvalidSharePayloadException::class, 'requires text/link content or a media URN');

it('rejects linkedin payloads with empty visibility after normalization', function (): void {
    $provider = new LinkedInProvider(
        providerConfig: ['base_url' => 'https://api.linkedin.com'],
        credentials: ['author' => 'urn:li:person:123', 'access_token' => 'li-token', 'version' => '202602'],
        httpConfig: testHttpConfig(),
        profile: 'default',
    );

    $provider->share(new SharePayload(
        message: 'hello',
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: ['visibility' => '   '],
        metadata: [],
    ));
})->throws(InvalidSharePayloadException::class, 'visibility cannot be empty');

it('rejects linkedin payloads with empty distribution after normalization', function (): void {
    $provider = new LinkedInProvider(
        providerConfig: ['base_url' => 'https://api.linkedin.com'],
        credentials: ['author' => 'urn:li:person:123', 'access_token' => 'li-token', 'version' => '202602'],
        httpConfig: testHttpConfig(),
        profile: 'default',
    );

    $provider->share(new SharePayload(
        message: 'hello',
        link: null,
        imageUrl: null,
        videoUrl: null,
        mediaIds: [],
        providerOptions: ['distribution' => '   '],
        metadata: [],
    ));
})->throws(InvalidSharePayloadException::class, 'distribution cannot be empty');
