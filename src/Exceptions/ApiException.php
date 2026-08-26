<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Exceptions;

use function array_key_exists;

use DrAliRagab\Socialize\Enums\Provider;
use Illuminate\Http\Client\Response;

use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_strtolower;
use function mb_substr;
use function preg_replace;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function strip_tags;

use Throwable;

final class ApiException extends SocializeException
{
    private const int RESPONSE_TEXT_LIMIT = 2_000;

    /**
     * @param array<string, mixed> $responseBody
     * @param array<string, string> $responseHeaders
     */
    private function __construct(
        string $message,
        private readonly Provider $provider,
        private readonly int $status,
        private readonly array $responseBody = [],
        private readonly ?string $responseText = null,
        private readonly array $responseHeaders = [],
        private readonly ?string $requestMethod = null,
        private readonly ?string $requestUrl = null,
        ?Throwable $throwable = null,
    ) {
        parent::__construct($message, 0, $throwable);
    }

    public static function fromResponse(
        Provider $provider,
        Response $response,
        ?string $requestMethod = null,
        ?string $requestUrl = null,
    ): self {
        /** @var array<string, mixed>|null $json */
        $json         = $response->json();
        $body         = is_array($json) ? $json : [];
        $responseText = $body === [] ? self::normalizeResponseText($response->body()) : null;
        $message      = self::extractResponseMessage($body, $responseText);
        $details      = self::formatDiagnosticDetails($body);
        $request      = self::formatRequest($requestMethod, $requestUrl);

        return new self(
            sprintf(
                '%s API request failed with status %d%s: %s%s',
                ucfirst($provider->value),
                $response->status(),
                $request,
                $message,
                $details,
            ),
            $provider,
            $response->status(),
            $body,
            $responseText,
            self::diagnosticResponseHeaders($response),
            self::normalizeRequestMethod($requestMethod),
            self::redactUrl($requestUrl),
        );
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    public static function invalidResponse(
        Provider $provider,
        string $message,
        int $status = 500,
        array $responseBody = [],
        ?Throwable $throwable = null,
        ?string $requestMethod = null,
        ?string $requestUrl = null,
    ): self {
        return new self(
            $message,
            $provider,
            $status,
            $responseBody,
            requestMethod: self::normalizeRequestMethod($requestMethod),
            requestUrl: self::redactUrl($requestUrl),
            throwable: $throwable,
        );
    }

    /**
     * @param array<string, mixed> $responseBody
     */
    public static function fromPayload(
        Provider $provider,
        int $status,
        string $message,
        array $responseBody = [],
        ?Throwable $throwable = null,
        ?string $requestMethod = null,
        ?string $requestUrl = null,
    ): self {
        return new self(
            sprintf(
                '%s API request failed with status %d%s: %s%s',
                ucfirst($provider->value),
                $status,
                self::formatRequest($requestMethod, $requestUrl),
                $message,
                self::formatDiagnosticDetails($responseBody),
            ),
            $provider,
            $status,
            $responseBody,
            requestMethod: self::normalizeRequestMethod($requestMethod),
            requestUrl: self::redactUrl($requestUrl),
            throwable: $throwable,
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

    public function responseText(): ?string
    {
        return $this->responseText;
    }

    /**
     * @return array<string, string>
     */
    public function responseHeaders(): array
    {
        return $this->responseHeaders;
    }

    public function requestMethod(): ?string
    {
        return $this->requestMethod;
    }

    public function requestUrl(): ?string
    {
        return $this->requestUrl;
    }

    /**
     * Laravel automatically includes this exception-specific context when reporting the exception.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        $context = [
            'provider' => $this->provider->value,
            'status'   => $this->status,
        ];

        if ($this->requestMethod !== null || $this->requestUrl !== null)
        {
            $context['request'] = array_filter([
                'method' => $this->requestMethod,
                'url'    => $this->requestUrl,
            ], static fn (?string $value): bool => $value !== null);
        }

        if ($this->responseBody !== [])
        {
            $context['provider_response'] = self::redactValue($this->responseBody);
        } elseif ($this->responseText !== null)
        {
            $context['provider_response'] = $this->responseText;
        }

        if ($this->responseHeaders !== [])
        {
            $context['provider_response_headers'] = $this->responseHeaders;
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function extractResponseMessage(array $body, ?string $responseText = null): string
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

        $debugInfo = $body['debug_info'] ?? null;

        if (is_array($debugInfo))
        {
            $debugMessage = $debugInfo['message'] ?? null;

            if (is_string($debugMessage) && mb_trim($debugMessage) !== '')
            {
                return mb_trim($debugMessage);
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

        return $responseText ?? 'API request failed.';
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function formatDiagnosticDetails(array $body): string
    {
        $error     = $body['error']      ?? null;
        $debugInfo = $body['debug_info'] ?? null;
        $details   = [];

        self::appendDetail($details, 'type', self::firstScalar(
            is_array($error) ? ($error['type'] ?? null) : null,
            is_array($debugInfo) ? ($debugInfo['type'] ?? null) : null,
            $body['type'] ?? null,
        ));
        self::appendDetail($details, 'code', self::firstScalar(
            is_array($error) ? ($error['code'] ?? null) : null,
            $body['code']             ?? null,
            $body['serviceErrorCode'] ?? null,
        ));
        self::appendDetail($details, 'subcode', self::firstScalar(
            is_array($error) ? ($error['error_subcode'] ?? null) : null,
            $body['error_subcode'] ?? null,
        ));
        self::appendDetail($details, 'status_code', $body['status_code'] ?? null);
        self::appendDetail($details, 'status', $body['status'] ?? null);
        self::appendDetail($details, 'retriable', self::firstScalar(
            is_array($debugInfo) ? ($debugInfo['retriable'] ?? null) : null,
            is_array($error) ? ($error['is_transient'] ?? null) : null,
            $body['retriable'] ?? null,
        ));
        self::appendDetail($details, 'trace_id', self::firstScalar(
            is_array($error) ? ($error['fbtrace_id'] ?? null) : null,
            $body['fbtrace_id'] ?? null,
            $body['trace_id']   ?? null,
        ));

        return $details === [] ? '' : sprintf(' [%s]', implode(', ', $details));
    }

    /**
     * @param list<string> $details
     */
    private static function appendDetail(array &$details, string $name, mixed $value): void
    {
        if (is_string($value) && mb_trim($value) !== '')
        {
            $details[] = sprintf('%s=%s', $name, mb_trim($value));

            return;
        }

        if (is_int($value))
        {
            $details[] = sprintf('%s=%d', $name, $value);

            return;
        }

        if (is_bool($value))
        {
            $details[] = sprintf('%s=%s', $name, $value ? 'true' : 'false');
        }
    }

    private static function firstScalar(mixed ...$values): mixed
    {
        foreach ($values as $value)
        {
            if (is_string($value) || is_int($value) || is_bool($value))
            {
                return $value;
            }
        }

        return null;
    }

    private static function normalizeResponseText(string $body): ?string
    {
        $text = preg_replace('/\s+/', ' ', strip_tags($body));
        $text = is_string($text) ? mb_trim($text) : '';

        if ($text === '')
        {
            return null;
        }

        if (mb_strlen($text) <= self::RESPONSE_TEXT_LIMIT)
        {
            return $text;
        }

        return mb_substr($text, 0, self::RESPONSE_TEXT_LIMIT) . '...';
    }

    /**
     * @return array<string, string>
     */
    private static function diagnosticResponseHeaders(Response $response): array
    {
        $headers = [];

        foreach ([
            'Content-Type',
            'Retry-After',
            'X-Request-Id',
            'X-RestLi-Id',
            'X-FB-Debug',
            'X-FB-Rev',
            'X-App-Usage',
            'X-Page-Usage',
        ] as $name)
        {
            $value = $response->header($name);

            if (mb_trim($value) !== '')
            {
                $headers[$name] = mb_trim($value);
            }
        }

        return $headers;
    }

    private static function formatRequest(?string $method, ?string $url): string
    {
        $method = self::normalizeRequestMethod($method);
        $url    = self::redactUrl($url);

        if ($method === null && $url === null)
        {
            return '';
        }

        return sprintf(' for %s%s', $method ?? 'request to', $url === null ? '' : ' ' . $url);
    }

    private static function normalizeRequestMethod(?string $method): ?string
    {
        return is_string($method) && mb_trim($method) !== '' ? mb_strtoupper(mb_trim($method)) : null;
    }

    private static function redactUrl(?string $url): ?string
    {
        if (! is_string($url) || mb_trim($url) === '')
        {
            return null;
        }

        $redacted = preg_replace_callback(
            '/([?&])([^=&#]+)=([^&#]*)/',
            static function (array $matches): string {
                $key = mb_strtolower($matches[2]);

                if (! self::isSensitiveKey($key))
                {
                    return $matches[0];
                }

                return $matches[1] . $matches[2] . '=[REDACTED]';
            },
            mb_trim($url),
        );

        return is_string($redacted) ? $redacted : mb_trim($url);
    }

    private static function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        foreach (['access_token', 'authorization', 'password', 'secret', 'api_key', 'apikey', 'uploadtoken', 'upload_token', 'signature', 'sig', 'cookie'] as $sensitive)
        {
            if (str_contains($key, $sensitive))
            {
                return true;
            }
        }

        return $key === 'token';
    }

    private static function redactValue(mixed $value, ?string $key = null): mixed
    {
        if (is_string($key) && self::isSensitiveKey($key))
        {
            return '[REDACTED]';
        }

        if (! is_array($value))
        {
            return $value;
        }

        $redacted = [];

        foreach ($value as $entryKey => $entryValue)
        {
            $redacted[$entryKey] = self::redactValue($entryValue, is_string($entryKey) ? $entryKey : null);
        }

        return $redacted;
    }
}
