<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\ValueObjects\CommentResult;

it('serializes comment result to array', function (): void {
    $result = new CommentResult(Provider::Twitter, '200', '100', 'https://x.com/i/web/status/200', ['ok' => true]);

    expect($result->provider())->toBe(Provider::Twitter)
        ->and($result->id())->toBe('200')
        ->and($result->postId())->toBe('100')
        ->and($result->url())->toBe('https://x.com/i/web/status/200')
        ->and($result->raw())->toBe(['ok' => true])
    ;

    expect($result->toArray())->toBe([
        'provider' => 'twitter',
        'id'       => '200',
        'post_id'  => '100',
        'url'      => 'https://x.com/i/web/status/200',
        'raw'      => ['ok' => true],
    ]);
});

it('json serializes comment result', function (): void {
    $result = new CommentResult(Provider::LinkedIn, 'urn:li:comment:1', 'urn:li:share:1', null, ['meta' => 'value']);

    expect($result->jsonSerialize())->toBe($result->toArray())
        ->and(json_decode((string)json_encode($result), true, flags: \JSON_THROW_ON_ERROR))->toBe($result->toArray())
    ;
});
