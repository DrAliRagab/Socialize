<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Exceptions;

use DrAliRagab\Socialize\Enums\Provider;
use Illuminate\Http\Client\Response;

use function is_array;
use function is_string;
use function sprintf;

final class ApiException extends SocializeException
{
    /**
     * @param array<string, mixed> $responseBody
     */
    private function __construct(
        string $message,
        private readonly Provider $provider,
        private readonly int $status,
        private readonly array $responseBody = [],
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Provider $provider, Response $response): self
    {
        /** @var array<string, mixed>|null $json */
        $json         = $response->json();
        $body         = is_array($json) ? $json : [];
        $error        = $body['error'] ?? null;
        $errorMessage = is_array($error) ? ($error['message'] ?? null) : null;
        $messageField = $body['message'] ?? null;
        $message      = is_string($errorMessage)
            ? $errorMessage
            : (is_string($messageField) ? $messageField : 'API request failed.');

        return new self(
            sprintf('%s API request failed with status %d: %s', ucfirst($provider->value), $response->status(), $message),
            $provider,
            $response->status(),
            $body,
        );
    }

    public static function invalidResponse(Provider $provider, string $message): self
    {
        return new self($message, $provider, 500);
    }

    public function provider(): Provider
    {
        return $this->provider;
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function responseBody(): array
    {
        return $this->responseBody;
    }
}
