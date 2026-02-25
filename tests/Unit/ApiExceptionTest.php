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

it('extracts problem detail message from title and detail fields', function (): void {
    $json = json_encode([
        'title'  => 'Unsupported Authentication',
        'detail' => 'OAuth 2.0 Application-Only is forbidden for this endpoint.',
    ]);
    $body = \is_string($json) ? $json : '{}';

    $response = new Response(new Psr7Response(403, [], $body));

    $apiException = ApiException::fromResponse(Provider::Twitter, $response);

    expect($apiException->getMessage())->toContain('Unsupported Authentication')
        ->and($apiException->getMessage())->toContain('Application-Only is forbidden')
    ;
});

it('extracts error message from errors array entries', function (): void {
    $json = json_encode([
        'errors' => [
            ['message' => 'First error message'],
            ['message' => 'Second error message'],
        ],
    ]);
    $body = \is_string($json) ? $json : '{}';

    $response = new Response(new Psr7Response(422, [], $body));

    $apiException = ApiException::fromResponse(Provider::LinkedIn, $response);

    expect($apiException->getMessage())->toContain('status 422')
        ->and($apiException->getMessage())->toContain('First error message')
    ;
});

it('extracts error detail when error message is missing', function (): void {
    $json = json_encode([
        'error' => [
            'detail' => 'Token missing media.write scope',
        ],
    ]);
    $body = \is_string($json) ? $json : '{}';

    $response = new Response(new Psr7Response(403, [], $body));

    $apiException = ApiException::fromResponse(Provider::Twitter, $response);

    expect($apiException->getMessage())->toContain('Token missing media.write scope');
});

it('extracts top-level detail when title and message are missing', function (): void {
    $json = json_encode([
        'detail' => 'Resource is temporarily unavailable.',
    ]);
    $body = \is_string($json) ? $json : '{}';

    $response = new Response(new Psr7Response(503, [], $body));

    $apiException = ApiException::fromResponse(Provider::LinkedIn, $response);

    expect($apiException->getMessage())->toContain('Resource is temporarily unavailable.');
});

it('skips malformed errors entries and uses detail entry', function (): void {
    $json = json_encode([
        'errors' => [
            'not-an-array',
            ['detail' => 'Detailed entry error'],
            ['title'  => 'Titled entry error'],
        ],
    ]);
    $body = \is_string($json) ? $json : '{}';

    $response = new Response(new Psr7Response(422, [], $body));

    $apiException = ApiException::fromResponse(Provider::Facebook, $response);

    expect($apiException->getMessage())->toContain('Detailed entry error');
});

it('falls back to title entry in errors array when message and detail are missing', function (): void {
    $json = json_encode([
        'errors' => [
            ['title' => 'Entry title fallback'],
        ],
    ]);
    $body = \is_string($json) ? $json : '{}';

    $response = new Response(new Psr7Response(422, [], $body));

    $apiException = ApiException::fromResponse(Provider::Instagram, $response);

    expect($apiException->getMessage())->toContain('Entry title fallback');
});
