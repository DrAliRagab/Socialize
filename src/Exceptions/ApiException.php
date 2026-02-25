<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Exceptions;

use function array_key_exists;

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
        $json    = $response->json();
        $body    = is_array($json) ? $json : [];
        $message = self::extractResponseMessage($body);

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

    /**
     * @param array<string, mixed> $responseBody
     */
    public static function fromPayload(Provider $provider, int $status, string $message, array $responseBody = []): self
    {
        return new self(
            sprintf('%s API request failed with status %d: %s', ucfirst($provider->value), $status, $message),
            $provider,
            $status,
            $responseBody,
        );
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

    /**
     * @param array<string, mixed> $body
     */
    private static function extractResponseMessage(array $body): string
    {
        $title  = $body['title']  ?? null;
        $detail = $body['detail'] ?? null;

        if (
            is_string($title)
            && mb_trim($title) !== ''
            && is_string($detail)
            && mb_trim($detail) !== ''
        ) {
            return sprintf('%s: %s', mb_trim($title), mb_trim($detail));
        }

        $error = $body['error'] ?? null;

        if (is_array($error))
        {
            $message = $error['message'] ?? null;

            if (is_string($message) && mb_trim($message) !== '')
            {
                return mb_trim($message);
            }

            $detail = $error['detail'] ?? null;

            if (is_string($detail) && mb_trim($detail) !== '')
            {
                return mb_trim($detail);
            }
        }

        $messageField = $body['message'] ?? null;

        if (is_string($messageField) && mb_trim($messageField) !== '')
        {
            return mb_trim($messageField);
        }

        if (is_string($detail) && mb_trim($detail) !== '')
        {
            return mb_trim($detail);
        }

        if (is_string($title) && mb_trim($title) !== '')
        {
            return mb_trim($title);
        }

        $errors = $body['errors'] ?? null;

        if (is_array($errors))
        {
            foreach ($errors as $entry)
            {
                if (! is_array($entry))
                {
                    continue;
                }

                if (array_key_exists('message', $entry) && is_string($entry['message']) && mb_trim($entry['message']) !== '')
                {
                    return mb_trim($entry['message']);
                }

                if (array_key_exists('detail', $entry) && is_string($entry['detail']) && mb_trim($entry['detail']) !== '')
                {
                    return mb_trim($entry['detail']);
                }

                if (array_key_exists('title', $entry) && is_string($entry['title']) && mb_trim($entry['title']) !== '')
                {
                    return mb_trim($entry['title']);
                }
            }
        }

        return 'API request failed.';
    }
}
