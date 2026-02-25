<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use function array_key_exists;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use const FILTER_VALIDATE_URL;

use Illuminate\Support\Facades\Http;

use function in_array;
use function is_array;
use function is_int;
use function is_string;

use const PHP_EOL;

use function rawurlencode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;

final class LinkedInProvider extends BaseProvider implements ProviderDriver
{
    public function provider(): Provider
    {
        return Provider::LinkedIn;
    }

    public function share(SharePayload $sharePayload): ShareResult
    {
        $this->requireCredentials('author', 'access_token');

        $mediaUrn    = $this->resolveMediaUrn($sharePayload);
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
            'author'       => $this->authorUrn(),
            'commentary'   => $this->buildCommentary($sharePayload, $hasMediaUrn),
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
            url: $this->resolvePostUrl($id, $body),
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

    private function buildCommentary(SharePayload $sharePayload, bool $hasMediaUrn = false): string
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

    private function authorUrn(): string
    {
        $author = (string)$this->credential('author');

        if (str_starts_with($author, 'urn:'))
        {
            return $author;
        }

        if (preg_match('/^\d+$/', $author) === 1)
        {
            return 'urn:li:person:' . $author;
        }

        throw new InvalidConfigException('LinkedIn author must be a URN (urn:li:person:...) or numeric person id.');
    }

    private function resolveMediaUrn(SharePayload $sharePayload): ?string
    {
        $explicitMediaUrn = $sharePayload->option('media_urn');

        if (is_string($explicitMediaUrn) && mb_trim($explicitMediaUrn) !== '')
        {
            return mb_trim($explicitMediaUrn);
        }

        $mediaSources = $this->mediaSourcesFromPayload($sharePayload);

        if ($mediaSources === [])
        {
            return null;
        }

        $firstSource = $mediaSources[0];

        return $this->uploadMediaAndGetUrn(
            $firstSource['source'],
            $firstSource['type'] ?? null,
        );
    }

    private function uploadMediaAndGetUrn(string $source, ?string $typeHint = null): string
    {
        $prepared = $this->prepareUploadSource($source);
        $cleanup  = $prepared['cleanup'];

        try
        {
            $media     = $this->loadBinaryMediaSource($prepared['source']);
            $mediaType = $this->inferMediaType($source, $typeHint, $media['mime_type']);

            if ($mediaType === 'image')
            {
                return $this->uploadImageAndReturnUrn($media['contents'], $media['mime_type']);
            }

            return $this->uploadVideoAndReturnUrn($media['contents'], $media['size'], $media['mime_type']);
        } finally
        {
            $cleanup();
        }
    }

    private function uploadImageAndReturnUrn(string $contents, ?string $mimeType): string
    {
        $initResponse = $this->decode($this->send(
            'POST',
            '/rest/images?action=initializeUpload',
            [
                'initializeUploadRequest' => [
                    'owner' => $this->authorUrn(),
                ],
            ],
            $this->headers(),
        ));

        $value = $initResponse['value'] ?? null;

        if (! is_array($value))
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn image initialize response is invalid.');
        }

        $uploadUrl = $value['uploadUrl'] ?? null;
        $imageUrn  = $value['image']     ?? null;

        if (! is_string($uploadUrl) || mb_trim($uploadUrl) === '' || ! is_string($imageUrn) || mb_trim($imageUrn) === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn image initialize response missing upload URL or image URN.');
        }

        $this->uploadToLinkedInAssetUrl(
            uploadUrl: mb_trim($uploadUrl),
            contents: $contents,
            mimeType: $this->linkedInContentType($mimeType, 'image'),
        );

        return mb_trim($imageUrn);
    }

    private function uploadVideoAndReturnUrn(string $contents, int $size, ?string $mimeType): string
    {
        $initResponse = $this->decode($this->send(
            'POST',
            '/rest/videos?action=initializeUpload',
            [
                'initializeUploadRequest' => [
                    'owner'           => $this->authorUrn(),
                    'fileSizeBytes'   => $size,
                    'uploadCaptions'  => false,
                    'uploadThumbnail' => false,
                    'uploadSubtitles' => false,
                ],
            ],
            $this->headers(),
        ));

        $value = $initResponse['value'] ?? null;

        if (! is_array($value))
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn video initialize response is invalid.');
        }

        $videoUrn    = $value['video']       ?? null;
        $uploadToken = $value['uploadToken'] ?? '';

        if (! is_string($videoUrn) || mb_trim($videoUrn) === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn video initialize response missing video URN.');
        }

        if (! is_string($uploadToken))
        {
            $uploadToken = '';
        }

        $uploaded        = false;
        $uploadedPartIds = [];

        if (array_key_exists('uploadInstructions', $value) && is_array($value['uploadInstructions']))
        {
            foreach ($value['uploadInstructions'] as $instruction)
            {
                if (! is_array($instruction))
                {
                    continue;
                }

                $uploadUrl = $instruction['uploadUrl'] ?? null;
                $firstByte = $instruction['firstByte'] ?? null;
                $lastByte  = $instruction['lastByte']  ?? null;

                if (! is_string($uploadUrl))
                {
                    continue;
                }

                if (mb_trim($uploadUrl) === '')
                {
                    continue;
                }

                if (! is_int($firstByte))
                {
                    continue;
                }

                if (! is_int($lastByte))
                {
                    continue;
                }

                if ($firstByte < 0)
                {
                    continue;
                }

                if ($lastByte < $firstByte)
                {
                    continue;
                }

                $length = ($lastByte - $firstByte) + 1;
                $chunk  = substr($contents, $firstByte, $length);

                if ($chunk === '')
                {
                    throw ApiException::invalidResponse($this->provider(), 'LinkedIn video upload chunk is empty.');
                }

                $etag = $this->uploadToLinkedInAssetUrl(
                    uploadUrl: mb_trim($uploadUrl),
                    contents: $chunk,
                    mimeType: $this->linkedInContentType($mimeType, 'video'),
                );

                if (is_string($etag) && $etag !== '')
                {
                    $uploadedPartIds[] = $etag;
                }

                $uploaded = true;
            }
        }

        if (! $uploaded)
        {
            $fallbackUploadUrl = $value['uploadUrl'] ?? null;

            if (! is_string($fallbackUploadUrl) || mb_trim($fallbackUploadUrl) === '')
            {
                throw ApiException::invalidResponse(
                    $this->provider(),
                    'LinkedIn video initialize response missing upload instructions.',
                );
            }

            $etag = $this->uploadToLinkedInAssetUrl(
                uploadUrl: mb_trim($fallbackUploadUrl),
                contents: $contents,
                mimeType: $this->linkedInContentType($mimeType, 'video'),
            );

            if (is_string($etag) && $etag !== '')
            {
                $uploadedPartIds[] = $etag;
            }
        }

        $this->finalizeVideoUpload(mb_trim($videoUrn), $uploadToken, $uploadedPartIds);

        return mb_trim($videoUrn);
    }

    private function uploadToLinkedInAssetUrl(string $uploadUrl, string $contents, string $mimeType): ?string
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->credential('access_token'),
            'Content-Type'  => $mimeType,
        ])
            ->timeout($this->intConfig('timeout', 15))
            ->connectTimeout($this->intConfig('connect_timeout', 10))
            ->retry(
                $this->intConfig('retries', 1),
                $this->intConfig('retry_sleep_ms', 150),
                throw: false,
            )
            ->withBody($contents, $mimeType)
            ->put($uploadUrl)
        ;

        if ($response->failed())
        {
            throw ApiException::fromResponse($this->provider(), $response);
        }

        $etag = mb_trim((string)$response->header('etag'));

        if ($etag === '')
        {
            return null;
        }

        return mb_trim($etag, "\"'");
    }

    /**
     * @param list<string> $uploadedPartIds
     */
    private function finalizeVideoUpload(string $videoUrn, string $uploadToken, array $uploadedPartIds): void
    {
        $body = [
            'finalizeUploadRequest' => [
                'video'       => $videoUrn,
                'uploadToken' => $uploadToken,
            ],
        ];

        if ($uploadedPartIds !== [])
        {
            $body['finalizeUploadRequest']['uploadedPartIds'] = $uploadedPartIds;
        }

        $this->send('POST', '/rest/videos?action=finalizeUpload', $body, $this->headers());

        if ($uploadedPartIds === [])
        {
            return;
        }

        $videoStatus = $this->decode($this->send(
            'GET',
            sprintf('/rest/videos/%s', rawurlencode($videoUrn)),
            [],
            $this->headers(),
        ));

        $status = $videoStatus['status'] ?? null;

        if (! is_string($status))
        {
            return;
        }

        $normalizedStatus = mb_strtoupper(mb_trim($status));

        if (in_array($normalizedStatus, ['PROCESSING_FAILED', 'FAILED'], true))
        {
            $reason = $videoStatus['processingFailureReason'] ?? 'unknown reason';

            throw ApiException::invalidResponse(
                $this->provider(),
                sprintf('LinkedIn video processing failed for [%s]: %s', $videoUrn, is_string($reason) ? $reason : 'unknown reason'),
            );
        }
    }

    private function linkedInContentType(?string $detectedMime, string $mediaType): string
    {
        if (is_string($detectedMime) && mb_trim($detectedMime) !== '')
        {
            $detectedMime = mb_strtolower(mb_trim($detectedMime));

            if (
                ($mediaType === 'image' && str_contains($detectedMime, 'image/'))
                || ($mediaType === 'video' && str_contains($detectedMime, 'video/'))
            ) {
                return $detectedMime;
            }
        }

        return $mediaType === 'video' ? 'video/mp4' : 'image/jpeg';
    }

    /**
     * @param array<string, mixed> $body
     */
    private function resolvePostUrl(string $id, array $body): ?string
    {
        $permalink = $body['permalink'] ?? $body['url'] ?? null;

        if (is_string($permalink) && mb_trim($permalink) !== '')
        {
            return mb_trim($permalink);
        }

        if (
            str_starts_with($id, 'urn:li:share:')
            || str_starts_with($id, 'urn:li:ugcPost:')
            || str_starts_with($id, 'urn:li:activity:')
        ) {
            return sprintf('https://www.linkedin.com/feed/update/%s/', $id);
        }

        return null;
    }
}
