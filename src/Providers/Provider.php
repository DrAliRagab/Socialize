<?php

namespace DrAliRagab\Socialize\Providers;

class Provider
{
    protected ?array $config;

    protected ?array $postData = [];

    protected $Client;

    private function apiResponse(string $method, string $endpoint, ?array $data = []): mixed
    {
        $response = $this->Client
            ->$method(
                $endpoint,
                $data
            );

        $this->throwExceptionIf(
            $response->failed(),
            "Error in {$method} {$endpoint}",
            $response->status(),
            $response->json()
        );

        return $response;
    }

    protected function getResponse(string $endpoint, ?array $data = []): array
    {
        $response = $this->apiResponse('get', $endpoint, $data);

        return $response->json();
    }

    protected function postResponse(string $endpoint, ?array $data = []): array
    {
        $response = $this->apiResponse('post', $endpoint, $data);

        return $response->json();
    }

    protected function deleteResponse(string $endpoint, ?array $data = []): array
    {
        $response = $this->apiResponse('delete', $endpoint, $data);

        return $response->json();
    }

    /**
     * Throw an exception
     */
    private function throwException(string $message, ?int $code = 500, ?array $context = []): void
    {
        $message .= ' '.json_encode($context);

        throw new \Exception($message, $code);
    }

    /**
     * Throw an exception if
     */
    protected function throwExceptionIf(bool $condition, ?string $message = 'Unknown error', ?int $code = 500, ?array $context = []): void
    {
        if ($condition) {
            $this->throwException($message, $code, $context);
        }
    }
}
