<?php

declare(strict_types=1);

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Providers\BaseProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

function makeBaseProviderStub(array $providerConfig = [], array $credentials = ['token' => ' abc ']): object
{
    return new class($providerConfig, $credentials, ['timeout' => 1, 'connect_timeout' => 1, 'retries' => 1, 'retry_sleep_ms' => 1], 'default') extends BaseProvider {
        public function callSend(string $method, string $path, array $data = [], array $headers = []): Response
        {
            return $this->send($method, $path, $data, $headers);
        }

        /**
         * @return array<string, mixed>
         */
        public function callDecode(Response $response): array
        {
            return $this->decode($response);
        }

        public function callCredential(string $key): ?string
        {
            return $this->credential($key);
        }

        public function callRequireCredentials(string ...$keys): void
        {
            $this->requireCredentials(...$keys);
        }

        public function callGraphVersion(): string
        {
            return $this->graphVersion();
        }

        protected function baseUrl(): string
        {
            return 'https://fake.test';
        }

        protected function providerName(): string
        {
            return 'fake';
        }

        protected function provider(): Provider
        {
            return Provider::Facebook;
        }
    };
}

it('supports all configured HTTP verbs in base provider', function (): void {
    Http::fake([
        'https://fake.test/*' => Http::response(['ok' => true], 200),
    ]);

    $provider = makeBaseProviderStub();

    expect($provider->callSend('GET', '/one')->status())->toBe(200)
        ->and($provider->callSend('POST', '/two')->status())->toBe(200)
        ->and($provider->callSend('DELETE', '/three')->status())->toBe(200)
        ->and($provider->callSend('PUT', '/four')->status())->toBe(200)
        ->and($provider->callSend('PATCH', '/five')->status())->toBe(200)
    ;
});

it('wraps unsupported HTTP method in api exception', function (): void {
    $provider = makeBaseProviderStub();

    $provider->callSend('TRACE', '/nope');
})->throws(ApiException::class, 'request failed before receiving a valid response');

it('throws api exception on failed response', function (): void {
    Http::fake([
        'https://fake.test/*' => Http::response(['message' => 'bad request'], 400),
    ]);

    $provider = makeBaseProviderStub();

    $provider->callSend('GET', '/bad');
})->throws(ApiException::class, 'status 400');

it('decodes response arrays and falls back to empty array for non arrays', function (): void {
    Http::fake([
        'https://fake.test/json' => Http::response(['ok' => true], 200),
        'https://fake.test/text' => Http::response('plain', 200),
    ]);

    $provider = makeBaseProviderStub();

    $json = $provider->callSend('GET', '/json');
    $text = $provider->callSend('GET', '/text');

    expect($provider->callDecode($json))->toBe(['ok' => true])
        ->and($provider->callDecode($text))->toBe([])
    ;
});

it('resolves credentials and validates required credentials', function (): void {
    $provider = makeBaseProviderStub([], ['token' => ' abc ', 'empty' => '']);

    expect($provider->callCredential('token'))->toBe('abc')
        ->and($provider->callCredential('empty'))->toBeNull()
    ;

    $provider->callRequireCredentials('missing');
})->throws(InvalidConfigException::class, 'Missing required credential [missing]');

it('returns configured graph version or default', function (): void {
    $configured = makeBaseProviderStub(['graph_version' => ' v77.0 ']);
    $default    = makeBaseProviderStub();

    expect($configured->callGraphVersion())->toBe('v77.0')
        ->and($default->callGraphVersion())->toBe('v25.0')
    ;
});
