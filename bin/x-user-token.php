#!/usr/bin/env php
<?php

declare(strict_types=1);

main($argv);

/**
 * @param array<int, string> $argv
 */
function main(array $argv): void
{
    try
    {
        $command = $argv[1] ?? null;

        if ($command === null || \in_array($command, ['-h', '--help', 'help'], true))
        {
            printHelp();

            return;
        }

        $options = parseOptions(\array_slice($argv, 2));
        $rootDir = \dirname(__DIR__);
        $envFile = optionString($options, 'env-file', '.testing.env');
        $envPath = str_starts_with($envFile, '/') ? $envFile : $rootDir . '/' . $envFile;

        if (! file_exists($envPath))
        {
            throw new InvalidArgumentException(
                \sprintf('Env file not found: %s. Create it from .testing.env.example first.', $envPath),
            );
        }

        loadEnvFile($envPath);

        $clientId     = requiredEnvValue('X_CLIENT_ID');
        $clientSecret = requiredEnvValue('X_CLIENT_SECRET');
        $redirectUri  = requiredEnvValue('X_REDIRECT_URL');
        $tokenUrl     = optionString($options, 'token-url', 'https://api.x.com/2/oauth2/token');

        if ($command === 'authorize')
        {
            $scopes       = normalizeScopes(optionString($options, 'scopes', 'tweet.read tweet.write users.read media.write offline.access'));
            $state        = optionString($options, 'state', bin2hex(random_bytes(16)));
            $codeVerifier = optionString($options, 'code-verifier', generateCodeVerifier());
            $challenge    = base64UrlEncode(hash('sha256', $codeVerifier, true));

            $query = http_build_query([
                'response_type'         => 'code',
                'client_id'             => $clientId,
                'redirect_uri'          => $redirectUri,
                'scope'                 => $scopes,
                'state'                 => $state,
                'code_challenge'        => $challenge,
                'code_challenge_method' => 'S256',
            ], '', '&', \PHP_QUERY_RFC3986);

            $authorizeUrl = 'https://x.com/i/oauth2/authorize?' . $query;

            output([
                'ok'            => true,
                'action'        => 'authorize',
                'authorize_url' => $authorizeUrl,
                'scopes'        => $scopes,
                'state'         => $state,
                'code_verifier' => $codeVerifier,
                'next_step'     => 'Open authorize_url, approve access, copy the code query parameter from redirect URL, then run exchange command.',
                'exchange_cmd'  => \sprintf(
                    'php bin/x-user-token.php exchange --code="PASTE_CODE" --code-verifier="%s"',
                    $codeVerifier,
                ),
            ]);

            return;
        }

        if ($command === 'exchange')
        {
            $code = null;

            if (\array_key_exists('callback-url', $options))
            {
                $code = extractCodeFromCallbackUrl(optionString($options, 'callback-url'));
            }

            if ($code === null)
            {
                $code = optionString($options, 'code');
            }

            $codeVerifier = optionString($options, 'code-verifier');

            $tokenResponse = tokenRequest($tokenUrl, $clientId, $clientSecret, [
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirectUri,
                'code_verifier' => $codeVerifier,
            ]);

            output([
                'ok'      => true,
                'action'  => 'exchange',
                'tokens'  => $tokenResponse,
                'env_set' => [
                    'SOCIALIZE_TWITTER_BEARER_TOKEN' => $tokenResponse['access_token'] ?? null,
                ],
            ]);

            return;
        }

        if ($command === 'refresh')
        {
            $refreshToken = optionString($options, 'refresh-token');

            $tokenResponse = tokenRequest($tokenUrl, $clientId, $clientSecret, [
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
            ]);

            output([
                'ok'      => true,
                'action'  => 'refresh',
                'tokens'  => $tokenResponse,
                'env_set' => [
                    'SOCIALIZE_TWITTER_BEARER_TOKEN' => $tokenResponse['access_token'] ?? null,
                ],
            ]);

            return;
        }

        throw new InvalidArgumentException(\sprintf('Unknown command [%s]. Use authorize, exchange, refresh, or --help.', $command));
    } catch (Throwable $throwable)
    {
        output([
            'ok'      => false,
            'error'   => $throwable::class,
            'message' => $throwable->getMessage(),
        ]);

        exit(1);
    }
}

function printHelp(): void
{
    $help = <<<'TXT'
X User Token Helper (OAuth 2.0 User Context with PKCE)

Usage:
  php bin/x-user-token.php authorize [options]
  php bin/x-user-token.php exchange --code="..." --code-verifier="..." [options]
  php bin/x-user-token.php exchange --callback-url="https://your/callback?state=...&code=..." --code-verifier="..." [options]
  php bin/x-user-token.php refresh --refresh-token="..." [options]

Options:
  --env-file=.testing.env
  --token-url=https://api.x.com/2/oauth2/token

Authorize options:
  --scopes="tweet.read tweet.write users.read media.write offline.access"
  --state="customstate"
  --code-verifier="customverifier"

Note:
  Required Socialize scopes are always included:
  tweet.read tweet.write users.read media.write offline.access

Required env vars:
  X_CLIENT_ID
  X_CLIENT_SECRET
  X_REDIRECT_URL

Flow:
  1) Run authorize command and open authorize_url.
  2) Approve and copy code from redirect URL.
  3) Run exchange command using that code + code_verifier from step 1.
  4) Set SOCIALIZE_TWITTER_BEARER_TOKEN to returned access_token.
TXT;

    fwrite(\STDOUT, $help . \PHP_EOL);
}

/**
 * @param list<string> $args
 * @return array<string, string>
 */
function parseOptions(array $args): array
{
    $options = [];

    foreach ($args as $arg)
    {
        if (! str_starts_with($arg, '--'))
        {
            throw new InvalidArgumentException(\sprintf('Invalid argument [%s]. Expected --key=value.', $arg));
        }

        $pair = mb_substr($arg, 2);

        if ($pair === '')
        {
            continue;
        }

        if (str_contains($pair, '='))
        {
            [$key, $value] = explode('=', $pair, 2);
        } else
        {
            $key   = $pair;
            $value = 'true';
        }

        if ($key === '')
        {
            continue;
        }

        $options[$key] = $value;
    }

    return $options;
}

/**
 * @param array<string, string> $options
 */
function optionString(array $options, string $key, ?string $default = null): string
{
    if (! \array_key_exists($key, $options) && $default === null)
    {
        throw new InvalidArgumentException(\sprintf('Missing required option [%s].', $key));
    }

    $value = $options[$key] ?? $default;

    if (! \is_string($value))
    {
        throw new InvalidArgumentException(\sprintf('Option [%s] must be a string.', $key));
    }

    $value = mb_trim($value);

    if ($value === '')
    {
        throw new InvalidArgumentException(\sprintf('Option [%s] is required and cannot be empty.', $key));
    }

    return $value;
}

function normalizeScopes(string $scopes): string
{
    $required = ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'];
    $custom   = preg_split('/\s+/', trim($scopes)) ?: [];
    $merged   = array_values(array_unique(array_merge($required, $custom)));

    return implode(' ', array_filter($merged, static fn (mixed $scope): bool => \is_string($scope) && trim($scope) !== ''));
}

function requiredEnvValue(string $key): string
{
    $value = envValue($key);

    if ($value === null)
    {
        throw new InvalidArgumentException(\sprintf('Missing required env var [%s].', $key));
    }

    return $value;
}

function envValue(string $key, ?string $default = null): ?string
{
    $value = $_ENV[$key] ?? getenv($key);

    if (! \is_string($value))
    {
        return $default;
    }

    $value = mb_trim($value);

    return $value === '' ? $default : $value;
}

function loadEnvFile(string $path): void
{
    $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

    if ($lines === false)
    {
        throw new InvalidArgumentException(\sprintf('Failed reading env file [%s].', $path));
    }

    foreach ($lines as $line)
    {
        $line = mb_trim($line);

        if ($line === '' || str_starts_with($line, '#'))
        {
            continue;
        }

        $line = str_starts_with($line, 'export ') ? mb_substr($line, 7) : $line;

        if (! str_contains($line, '='))
        {
            continue;
        }

        [$key, $raw] = explode('=', $line, 2);
        $key         = mb_trim($key);
        $value       = parseEnvValue($raw);

        if ($key === '')
        {
            continue;
        }

        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }
}

function parseEnvValue(string $raw): string
{
    $value = mb_trim($raw);

    if ($value === '')
    {
        return '';
    }

    if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'")))
    {
        $value = mb_substr($value, 1, -1);
    }

    return str_replace(['\n', '\r', '\t'], [\PHP_EOL, "\r", "\t"], $value);
}

/**
 * @param array<string, string> $form
 * @return array<string, mixed>
 */
function tokenRequest(string $tokenUrl, string $clientId, string $clientSecret, array $form): array
{
    $body  = http_build_query($form, '', '&', \PHP_QUERY_RFC3986);
    $basic = base64_encode($clientId . ':' . $clientSecret);
    $ch    = curl_init($tokenUrl);

    if ($ch === false)
    {
        throw new RuntimeException('Failed to initialize cURL.');
    }

    curl_setopt_array($ch, [
        \CURLOPT_POST           => true,
        \CURLOPT_RETURNTRANSFER => true,
        \CURLOPT_TIMEOUT        => 30,
        \CURLOPT_CONNECTTIMEOUT => 10,
        \CURLOPT_POSTFIELDS     => $body,
        \CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'Authorization: Basic ' . $basic,
        ],
    ]);

    $raw = curl_exec($ch);

    if ($raw === false)
    {
        $error = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException(\sprintf('Token request failed before response: %s', $error));
    }

    $statusCode = (int)curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);

    if (! \is_array($decoded))
    {
        throw new RuntimeException(\sprintf('Token response is not valid JSON. HTTP %d body: %s', $statusCode, $raw));
    }

    if ($statusCode >= 400)
    {
        $title  = \is_string($decoded['title'] ?? null) ? $decoded['title'] : 'Token exchange failed';
        $detail = \is_string($decoded['detail'] ?? null) ? $decoded['detail'] : (\is_string($decoded['error_description'] ?? null) ? $decoded['error_description'] : 'Unknown error');

        throw new RuntimeException(\sprintf('HTTP %d: %s - %s', $statusCode, $title, $detail));
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

function generateCodeVerifier(): string
{
    $verifier = base64UrlEncode(random_bytes(64));

    if (mb_strlen($verifier) < 43)
    {
        $verifier .= str_repeat('A', 43 - mb_strlen($verifier));
    }

    if (mb_strlen($verifier) > 128)
    {
        $verifier = mb_substr($verifier, 0, 128);
    }

    return $verifier;
}

function base64UrlEncode(string $binary): string
{
    return mb_rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
}

function extractCodeFromCallbackUrl(string $callbackUrl): string
{
    $query = parse_url($callbackUrl, \PHP_URL_QUERY);

    if (! \is_string($query) || mb_trim($query) === '')
    {
        throw new InvalidArgumentException('callback-url does not contain query parameters.');
    }

    parse_str($query, $params);

    $code = $params['code'] ?? null;

    if (! \is_string($code) || mb_trim($code) === '')
    {
        throw new InvalidArgumentException('callback-url query does not include a valid code parameter.');
    }

    return mb_trim($code);
}

/**
 * @param array<string, mixed> $data
 */
function output(array $data): void
{
    $encoded = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    fwrite(\STDOUT, $encoded . \PHP_EOL);
}
