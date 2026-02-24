<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\Exceptions\UnsupportedFeatureException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use const FILTER_VALIDATE_URL;

use function is_array;
use function is_string;

use const PHP_EOL;

use function sprintf;

final class TwitterProvider extends BaseProvider implements ProviderDriver
{
    public function provider(): Provider
    {
        return Provider::Twitter;
    }

    public function share(SharePayload $sharePayload): ShareResult
    {
        $this->requireCredentials('bearer_token');

        if (! $sharePayload->hasAnyCoreContent())
        {
            throw new InvalidSharePayloadException('X share requires text, link, or media ids.');
        }

        if (($sharePayload->imageUrl() !== null || $sharePayload->videoUrl() !== null) && $sharePayload->mediaIds() === [])
        {
            throw new UnsupportedFeatureException('X API media upload should be done before sharing; pass media ids via mediaId/mediaIds.');
        }

        $body = [];
        $text = $this->buildText($sharePayload);

        if ($text !== null)
        {
            $body['text'] = $text;
        }

        if ($sharePayload->mediaIds() !== [])
        {
            $body['media'] = [
                'media_ids' => $sharePayload->mediaIds(),
            ];
        }

        $replyTo = $sharePayload->option('reply_to');

        if (is_string($replyTo) && $replyTo !== '')
        {
            $body['reply'] = [
                'in_reply_to_tweet_id' => $replyTo,
            ];
        }

        $quoteId = $sharePayload->option('quote_tweet_id');

        if (is_string($quoteId) && $quoteId !== '')
        {
            $body['quote_tweet_id'] = $quoteId;
        }

        $poll = $sharePayload->option('poll');

        if (is_array($poll) && $poll !== [])
        {
            $body['poll'] = $poll;
        }

        if ($body === [])
        {
            throw new InvalidSharePayloadException('X share payload resolved to empty body.');
        }

        $response = $this->decode($this->send('POST', '/2/tweets', $body, $this->headers()));
        $data     = $response['data'] ?? null;
        $id       = is_array($data) ? ($data['id'] ?? null) : null;

        if (! is_string($id) || $id === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'X API did not return a post id.');
        }

        return new ShareResult(
            provider: $this->provider(),
            id: $id,
            url: sprintf('https://x.com/i/web/status/%s', $id),
            raw: $response,
        );
    }

    public function delete(string $postId): bool
    {
        $this->requireCredentials('bearer_token');
        $postId = mb_trim($postId);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('X post id cannot be empty.');
        }

        $response = $this->decode($this->send('DELETE', sprintf('/2/tweets/%s', $postId), [], $this->headers()));
        $data     = $response['data'] ?? null;

        return (bool)(is_array($data) ? ($data['deleted'] ?? false) : false);
    }

    protected function providerName(): string
    {
        return 'twitter';
    }

    protected function baseUrl(): string
    {
        $base = $this->providerConfig['base_url'] ?? 'https://api.x.com';

        return is_string($base) ? mb_rtrim($base, '/') : 'https://api.x.com';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->credential('bearer_token'),
        ];
    }

    private function buildText(SharePayload $sharePayload): ?string
    {
        $parts = [];

        if (is_string($sharePayload->message()) && mb_trim($sharePayload->message()) !== '')
        {
            $parts[] = mb_trim($sharePayload->message());
        }

        if (is_string($sharePayload->link()) && mb_trim($sharePayload->link()) !== '')
        {
            if (filter_var($sharePayload->link(), FILTER_VALIDATE_URL) === false)
            {
                throw new InvalidSharePayloadException('X share link must be a valid URL.');
            }

            $parts[] = mb_trim($sharePayload->link());
        }

        if ($parts === [])
        {
            return null;
        }

        return implode(PHP_EOL . PHP_EOL, $parts);
    }
}
