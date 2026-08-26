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

it('supports invalid response exception custom status body and previous', function (): void {
    $previous = new RuntimeException('network timeout');

    $apiException = ApiException::invalidResponse(
        Provider::LinkedIn,
        'transport failed',
        422,
        ['error' => ['message' => 'unprocessable']],
        $previous,
    );

    expect($apiException->provider())->toBe(Provider::LinkedIn)
        ->and($apiException->status())->toBe(422)
        ->and($apiException->responseBody())->toBe(['error' => ['message' => 'unprocessable']])
        ->and($apiException->getPrevious())->toBe($previous)
    ;
});

it('builds api exception from provider payload with explicit status and body', function (): void {
    $previous = new RuntimeException('original');

    $apiException = ApiException::fromPayload(
        Provider::Instagram,
        400,
        'Container failed',
        ['status_code' => 'ERROR', 'status' => 'ERROR'],
        $previous,
    );

    expect($apiException->provider())->toBe(Provider::Instagram)
        ->and($apiException->status())->toBe(400)
        ->and($apiException->responseBody())->toBe(['status_code' => 'ERROR', 'status' => 'ERROR'])
        ->and($apiException->getMessage())->toContain('status 400')
        ->and($apiException->getMessage())->toContain('Container failed')
        ->and($apiException->getPrevious())->toBe($previous)
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

it('extracts nested provider diagnostics and exposes redacted Laravel log context', function (): void {
    $json = json_encode([
        'debug_info' => [
            'retriable' => false,
            'type'      => 'ProcessingFailedError',
            'message'   => 'Request processing failed',
        ],
        'access_token' => 'provider-secret',
        'nested'       => [
            'uploadToken' => 'upload-secret',
            'safe'        => 'visible',
        ],
    ]);
    $body = \is_string($json) ? $json : '{}';

    $response = new Response(new Psr7Response(400, [
        'Content-Type' => 'application/json',
        'X-FB-Debug'   => 'meta-debug-id',
        'Set-Cookie'   => 'session=secret',
    ], $body));

    $apiException = ApiException::fromResponse(
        Provider::Instagram,
        $response,
        'post',
        '/v25.0/123/media_publish?action=publish&access_token=request-secret',
    );

    expect($apiException->getMessage())
        ->toContain('status 400 for POST /v25.0/123/media_publish?action=publish&access_token=[REDACTED]')
        ->toContain('Request processing failed')
        ->toContain('type=ProcessingFailedError')
        ->toContain('retriable=false')
        ->and($apiException->requestMethod())->toBe('POST')
        ->and($apiException->requestUrl())->toBe('/v25.0/123/media_publish?action=publish&access_token=[REDACTED]')
        ->and($apiException->responseText())->toBeNull()
        ->and($apiException->responseHeaders())->toBe([
            'Content-Type' => 'application/json',
            'X-FB-Debug'   => 'meta-debug-id',
        ])
        ->and($apiException->context())->toBe([
            'provider' => 'instagram',
            'status'   => 400,
            'request'  => [
                'method' => 'POST',
                'url'    => '/v25.0/123/media_publish?action=publish&access_token=[REDACTED]',
            ],
            'provider_response' => [
                'debug_info' => [
                    'retriable' => false,
                    'type'      => 'ProcessingFailedError',
                    'message'   => 'Request processing failed',
                ],
                'access_token' => '[REDACTED]',
                'nested'       => [
                    'uploadToken' => '[REDACTED]',
                    'safe'        => 'visible',
                ],
            ],
            'provider_response_headers' => [
                'Content-Type' => 'application/json',
                'X-FB-Debug'   => 'meta-debug-id',
            ],
        ])
    ;
});

it('preserves bounded plain text provider errors', function (): void {
    $response = new Response(new Psr7Response(
        502,
        ['Content-Type' => 'text/html', 'Retry-After' => '30'],
        '<html><body>Gateway failure ' . str_repeat('x', 2_100) . '</body></html>',
    ));

    $apiException = ApiException::fromResponse(Provider::LinkedIn, $response);

    expect($apiException->responseBody())->toBe([])
        ->and($apiException->responseText())->toStartWith('Gateway failure')
        ->and($apiException->responseText())->toEndWith('...')
        ->and(mb_strlen((string)$apiException->responseText()))->toBe(2_003)
        ->and($apiException->getMessage())->toContain('Gateway failure')
        ->and($apiException->context()['provider_response'])->toBe($apiException->responseText())
        ->and($apiException->context()['provider_response_headers'])->toBe([
            'Content-Type' => 'text/html',
            'Retry-After'  => '30',
        ])
    ;
});

it('includes common provider codes statuses and trace ids in the exception message', function (): void {
    $json = json_encode([
        'error' => [
            'message'       => 'Media is not ready',
            'type'          => 'OAuthException',
            'code'          => 9007,
            'error_subcode' => 2207027,
            'is_transient'  => true,
            'fbtrace_id'    => 'trace-123',
        ],
        'status_code' => 'IN_PROGRESS',
        'status'      => 'Processing',
    ]);
    $body     = \is_string($json) ? $json : '{}';
    $response = new Response(new Psr7Response(400, [], $body));

    $apiException = ApiException::fromResponse(Provider::Instagram, $response);

    expect($apiException->getMessage())->toContain('type=OAuthException')
        ->toContain('code=9007')
        ->toContain('subcode=2207027')
        ->toContain('status_code=IN_PROGRESS')
        ->toContain('status=Processing')
        ->toContain('retriable=true')
        ->toContain('trace_id=trace-123')
    ;
});

it('adds request metadata to transport and payload exceptions', function (): void {
    $apiException = ApiException::invalidResponse(
        Provider::Twitter,
        'Connection failed',
        throwable: new RuntimeException('timeout'),
        requestMethod: 'get',
        requestUrl: 'https://api.example.com/items?api_key=secret',
    );

    $payload = ApiException::fromPayload(
        Provider::Instagram,
        422,
        'Container failed',
        ['status_code' => 'ERROR'],
        requestMethod: 'GET',
        requestUrl: '/container-1',
    );

    expect($apiException->context())->toBe([
        'provider' => 'twitter',
        'status'   => 500,
        'request'  => [
            'method' => 'GET',
            'url'    => 'https://api.example.com/items?api_key=[REDACTED]',
        ],
    ])->and($payload->getMessage())->toContain('for GET /container-1')
        ->toContain('status_code=ERROR')
        ->and($payload->context()['provider_response'])->toBe(['status_code' => 'ERROR'])
    ;
});
