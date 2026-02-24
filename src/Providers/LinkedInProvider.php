<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use const FILTER_VALIDATE_URL;

use function is_string;

use const PHP_EOL;

use function sprintf;

final class LinkedInProvider extends BaseProvider implements ProviderDriver
{
    public function provider(): Provider
    {
        return Provider::LinkedIn;
    }

    public function share(SharePayload $sharePayload): ShareResult
    {
        $this->requireCredentials('author', 'access_token');

        $mediaUrn    = $sharePayload->option('media_urn');
        $hasMediaUrn = is_string($mediaUrn) && mb_trim($mediaUrn) !== '';

        if (! $sharePayload->hasAnyCoreContent() && ! $hasMediaUrn)
        {
            throw new InvalidSharePayloadException('LinkedIn share requires text/link content or a media URN.');
        }

        $visibility   = $sharePayload->option('visibility', 'PUBLIC');
        $distribution = $sharePayload->option('distribution', 'MAIN_FEED');
        $visibility   = mb_strtoupper(mb_trim(is_string($visibility) ? $visibility : 'PUBLIC'));
        $distribution = mb_strtoupper(mb_trim(is_string($distribution) ? $distribution : 'MAIN_FEED'));

        if ($visibility === '')
        {
            throw new InvalidSharePayloadException('LinkedIn visibility cannot be empty.');
        }

        if ($distribution === '')
        {
            throw new InvalidSharePayloadException('LinkedIn distribution cannot be empty.');
        }

        $payloadBody = [
            'author'       => $this->credential('author'),
            'commentary'   => $this->buildCommentary($sharePayload),
            'visibility'   => $visibility,
            'distribution' => [
                'feedDistribution'               => $distribution,
                'targetEntities'                 => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState'            => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if (is_string($sharePayload->link()) && mb_trim($sharePayload->link()) !== '')
        {
            if (filter_var($sharePayload->link(), FILTER_VALIDATE_URL) === false)
            {
                throw new InvalidSharePayloadException('LinkedIn link must be a valid URL.');
            }

            $payloadBody['content'] = [
                'article' => [
                    'source' => mb_trim($sharePayload->link()),
                    'title'  => $this->articleTitle($sharePayload),
                ],
            ];
        }

        if ($hasMediaUrn)
        {
            $payloadBody['content'] = [
                'media' => [
                    'id' => mb_trim($mediaUrn),
                ],
            ];
        }

        $response = $this->send('POST', '/rest/posts', $payloadBody, $this->headers());
        $body     = $this->decode($response);

        $id = $body['id'] ?? $response->header('x-restli-id');

        if (! is_string($id) || $id === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn API did not return a post id.');
        }

        return new ShareResult(
            provider: $this->provider(),
            id: $id,
            url: null,
            raw: $body,
        );
    }

    public function delete(string $postId): bool
    {
        $this->requireCredentials('access_token');
        $postId = mb_trim($postId);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('LinkedIn post id cannot be empty.');
        }

        $encodedId = rawurlencode($postId);
        $response  = $this->send('DELETE', sprintf('/rest/posts/%s', $encodedId), [], $this->headers());

        if ($response->status() === 204)
        {
            return true;
        }

        $body = $this->decode($response);

        return (bool)($body['success'] ?? false);
    }

    protected function providerName(): string
    {
        return 'linkedin';
    }

    protected function baseUrl(): string
    {
        $base = $this->providerConfig['base_url'] ?? 'https://api.linkedin.com';

        return is_string($base) ? mb_rtrim($base, '/') : 'https://api.linkedin.com';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization'             => 'Bearer ' . $this->credential('access_token'),
            'Linkedin-Version'          => $this->linkedinVersion(),
            'X-Restli-Protocol-Version' => '2.0.0',
        ];
    }

    private function linkedinVersion(): string
    {
        $version = $this->credential('version');

        if ($version === null)
        {
            return '202602';
        }

        if (preg_match('/^\d{6}$/', $version) !== 1)
        {
            throw new InvalidConfigException('LinkedIn version must use YYYYMM format.');
        }

        return $version;
    }

    private function buildCommentary(SharePayload $sharePayload): string
    {
        $parts = [];

        if (is_string($sharePayload->message()) && mb_trim($sharePayload->message()) !== '')
        {
            $parts[] = mb_trim($sharePayload->message());
        }

        if (is_string($sharePayload->link()) && mb_trim($sharePayload->link()) !== '')
        {
            $parts[] = mb_trim($sharePayload->link());
        }

        $commentary = implode(PHP_EOL . PHP_EOL, $parts);

        $mediaUrn    = $sharePayload->option('media_urn');
        $hasMediaUrn = is_string($mediaUrn) && mb_trim($mediaUrn) !== '';

        if ($commentary === '' && ! $hasMediaUrn)
        {
            throw new InvalidSharePayloadException('LinkedIn requires non-empty commentary, link, or media_urn.');
        }

        return $commentary;
    }

    private function articleTitle(SharePayload $sharePayload): string
    {
        $title = $sharePayload->option('article_title');

        if (is_string($title) && mb_trim($title) !== '')
        {
            return mb_trim($title);
        }

        if (is_string($sharePayload->message()) && mb_trim($sharePayload->message()) !== '')
        {
            return mb_trim($sharePayload->message());
        }

        return 'Shared link';
    }
}
