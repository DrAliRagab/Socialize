<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;

use Throwable;

abstract class BaseProvider
{
    /**
     * @param array<string, mixed> $providerConfig
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $httpConfig
     */
    public function __construct(
        protected readonly array $providerConfig,
        protected readonly array $credentials,
        protected readonly array $httpConfig,
        protected readonly string $profile,
    ) {}

    abstract protected function baseUrl(): string;

    abstract protected function providerName(): string;

    /**
     * @param array<string, string> $headers
     */
    protected function pendingRequest(array $headers = []): PendingRequest
    {
        return Http::withHeaders($headers)
            ->baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout($this->intConfig('timeout', 15))
            ->connectTimeout($this->intConfig('connect_timeout', 10))
            ->retry(
                $this->intConfig('retries', 1),
                $this->intConfig('retry_sleep_ms', 150),
                throw: false,
            )
        ;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    protected function send(string $method, string $path, array $data = [], array $headers = []): Response
    {
        $method         = mb_strtoupper($method);
        $pendingRequest = $this->pendingRequest($headers);

        try
        {
            $response = match ($method)
            {
                'GET'    => $pendingRequest->get($path, $data),
                'POST'   => $pendingRequest->post($path, $data),
                'DELETE' => $pendingRequest->delete($path, $data),
                'PUT'    => $pendingRequest->put($path, $data),
                'PATCH'  => $pendingRequest->patch($path, $data),
                default  => throw new InvalidArgumentException(sprintf('Unsupported HTTP method [%s].', $method)),
            };
        } catch (Throwable $throwable)
        {
            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('%s request failed before receiving a valid response: %s', $this->providerName(), $throwable->getMessage()),
            );
        }

        if ($response->failed())
        {
            throw ApiException::fromResponse($this->provider(), $response);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json))
        {
            return [];
        }

        /** @var array<string, mixed> $json */
        return $json;
    }

    protected function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    protected function requireCredentials(string ...$keys): void
    {
        foreach ($keys as $key)
        {
            if ($this->credential($key) === null)
            {
                throw new InvalidConfigException(
                    sprintf('Missing required credential [%s] for provider [%s] and profile [%s].', $key, $this->providerName(), $this->profile),
                );
            }
        }
    }

    protected function graphVersion(): string
    {
        $version = $this->providerConfig['graph_version'] ?? null;

        return is_string($version) && mb_trim($version) !== '' ? mb_trim($version) : 'v25.0';
    }

    abstract protected function provider(): Provider;

    private function intConfig(string $key, int $default): int
    {
        $value = $this->httpConfig[$key] ?? null;

        return is_int($value) ? $value : $default;
    }
}
