<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;

it('builds api exception from response and exposes getters', function (): void {
    $json     = json_encode(['error' => ['message' => 'Bad token']]);
    $body     = \is_string($json) ? $json : '{}';
    $response = new Response(new Psr7Response(401, [], $body));

    $apiException = ApiException::fromResponse(Provider::Facebook, $response);

    expect($apiException->provider())->toBe(Provider::Facebook)
        ->and($apiException->status())->toBe(401)
        ->and($apiException->responseBody())->toBe(['error' => ['message' => 'Bad token']])
        ->and($apiException->getMessage())->toContain('status 401')
    ;
});

it('builds invalid response exception with status 500', function (): void {
    $apiException = ApiException::invalidResponse(Provider::Twitter, 'broken payload');

    expect($apiException->provider())->toBe(Provider::Twitter)
        ->and($apiException->status())->toBe(500)
        ->and($apiException->responseBody())->toBe([])
        ->and($apiException->getMessage())->toBe('broken payload')
    ;
});
