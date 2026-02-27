<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Providers;

use function array_key_exists;
use function basename;

use DrAliRagab\Socialize\Contracts\ProviderDriver;
use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\Exceptions\ApiException;
use DrAliRagab\Socialize\Exceptions\InvalidConfigException;
use DrAliRagab\Socialize\Exceptions\InvalidSharePayloadException;
use DrAliRagab\Socialize\ValueObjects\CommentResult;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use function fclose;
use function feof;
use function file_exists;
use function file_get_contents;
use function filesize;

use const FILTER_VALIDATE_URL;

use function fopen;
use function fread;
use function fseek;
use function in_array;
use function is_array;
use function is_int;
use function is_readable;
use function is_string;
use function mime_content_type;

use const PHP_EOL;

use function rawurlencode;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;
use function usleep;

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

        $content = [];

        if (is_string($sharePayload->link()) && mb_trim($sharePayload->link()) !== '')
        {
            if (filter_var($sharePayload->link(), FILTER_VALIDATE_URL) === false)
            {
                throw new InvalidSharePayloadException('LinkedIn link must be a valid URL.');
            }

            $content['article'] = [
                'source' => mb_trim($sharePayload->link()),
                'title'  => $this->articleTitle($sharePayload),
            ];
        }

        if ($hasMediaUrn)
        {
            $content['media'] = [
                'id' => mb_trim($mediaUrn),
            ];
        }

        if ($content !== [])
        {
            $payloadBody['content'] = $content;
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

    public function comment(string $postId, string $message): CommentResult
    {
        $this->requireCredentials('author', 'access_token');

        $postId  = mb_trim($postId);
        $message = mb_trim($message);

        if ($postId === '')
        {
            throw new InvalidSharePayloadException('LinkedIn comment post id cannot be empty.');
        }

        if ($message === '')
        {
            throw new InvalidSharePayloadException('LinkedIn comment message cannot be empty.');
        }

        $response = $this->send(
            'POST',
            sprintf('/v2/socialActions/%s/comments', rawurlencode($postId)),
            [
                'actor'   => $this->authorUrn(),
                'message' => [
                    'text' => $message,
                ],
            ],
            [
                'Authorization' => 'Bearer ' . $this->credential('access_token'),
            ],
        );

        $body = $this->decode($response);
        $id   = $body['id'] ?? $response->header('x-restli-id');

        if (! is_string($id) || $id === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn API did not return a comment id.');
        }

        return new CommentResult(
            provider: $this->provider(),
            id: $id,
            postId: $postId,
            url: null,
            raw: $body,
        );
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

        return implode(PHP_EOL . PHP_EOL, $parts);
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
            $media     = $this->localMediaMetadata($prepared['source']);
            $mediaType = $this->inferMediaType($source, $typeHint, $media['mime_type']);

            if ($mediaType === 'image')
            {
                $contents = (string)file_get_contents($prepared['source']);

                return $this->uploadImageAndReturnUrn($contents, $media['mime_type']);
            }

            return $this->uploadVideoAndReturnUrn($prepared['source'], $media['size'], $media['mime_type']);
        } finally
        {
            $cleanup();
        }
    }

    /**
     * @return array{mime_type: ?string, file_name: string, size: int}
     */
    private function localMediaMetadata(string $path): array
    {
        if (! file_exists($path) || ! is_readable($path))
        {
            throw new InvalidSharePayloadException(sprintf('Media source path does not exist or is not readable [%s].', $path));
        }

        $size = filesize($path);

        if (! is_int($size) || $size <= 0)
        {
            throw new InvalidSharePayloadException(sprintf('Media source [%s] is empty or unreadable.', $path));
        }

        $detectedMime = mime_content_type($path);
        $mimeType     = is_string($detectedMime) && mb_trim($detectedMime) !== '' ? mb_trim($detectedMime) : null;

        return [
            'mime_type' => $mimeType,
            'file_name' => basename($path),
            'size'      => $size,
        ];
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

    private function uploadVideoAndReturnUrn(string $filePath, int $size, ?string $mimeType): string
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

                $chunk = $this->readRangeFromFile($filePath, $firstByte, $lastByte);

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

            $etag = $this->uploadFileToLinkedInAssetUrl(
                uploadUrl: mb_trim($fallbackUploadUrl),
                filePath: $filePath,
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

    private function readRangeFromFile(string $filePath, int $firstByte, int $lastByte): string
    {
        $length = ($lastByte - $firstByte) + 1;

        $handle = fopen($filePath, 'rb');

        // @codeCoverageIgnoreStart
        if ($handle === false)
        {
            throw new InvalidSharePayloadException(sprintf('Media source path does not exist or is not readable [%s].', $filePath));
        }

        // @codeCoverageIgnoreEnd

        try
        {
            fseek($handle, $firstByte);

            $remaining = $length;
            $chunk     = '';

            while ($remaining > 0 && ! feof($handle))
            {
                $readSize = min($remaining, 1024 * 1024);
                $buffer   = fread($handle, $readSize);

                // @codeCoverageIgnoreStart
                if ($buffer === false)
                {
                    throw ApiException::invalidResponse($this->provider(), 'Failed reading LinkedIn video upload chunk.');
                }

                // @codeCoverageIgnoreEnd

                if ($buffer === '')
                {
                    break;
                }

                $chunk .= $buffer;
                $remaining -= strlen($buffer);
            }
        } finally
        {
            fclose($handle);
        }

        if ($chunk === '')
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn video upload chunk is empty.');
        }

        if (strlen($chunk) !== $length)
        {
            throw ApiException::invalidResponse($this->provider(), 'LinkedIn video upload chunk length mismatch.');
        }

        return $chunk;
    }

    private function uploadToLinkedInAssetUrl(string $uploadUrl, string $contents, string $mimeType): ?string
    {
        $response = $this->sendBinary(
            method: 'PUT',
            url: $uploadUrl,
            contents: $contents,
            contentType: $mimeType,
            headers: [
                'Authorization' => 'Bearer ' . $this->credential('access_token'),
                'Content-Type'  => $mimeType,
            ],
        );

        $etag = mb_trim((string)$response->header('etag'));

        if ($etag === '')
        {
            return null;
        }

        return mb_trim($etag, "\"'");
    }

    private function uploadFileToLinkedInAssetUrl(string $uploadUrl, string $filePath, string $mimeType): ?string
    {
        $response = $this->sendBinaryFile(
            method: 'PUT',
            url: $uploadUrl,
            filePath: $filePath,
            contentType: $mimeType,
            headers: [
                'Authorization' => 'Bearer ' . $this->credential('access_token'),
                'Content-Type'  => $mimeType,
            ],
        );

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

        $this->waitForLinkedInVideoAvailability($videoUrn);
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

    private function waitForLinkedInVideoAvailability(string $videoUrn): void
    {
        $maxAttempts  = $this->videoStatusPollAttempts();
        $sleepSeconds = $this->videoStatusPollSleepSeconds();

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++)
        {
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

            if ($normalizedStatus === 'AVAILABLE')
            {
                return;
            }

            if (in_array($normalizedStatus, ['PROCESSING_FAILED', 'FAILED'], true))
            {
                $reason = $videoStatus['processingFailureReason'] ?? 'unknown reason';

                throw ApiException::invalidResponse(
                    $this->provider(),
                    sprintf('LinkedIn video processing failed for [%s]: %s', $videoUrn, is_string($reason) ? $reason : 'unknown reason'),
                );
            }

            if ($attempt >= $maxAttempts - 1)
            {
                break;
            }

            if ($sleepSeconds > 0)
            {
                usleep($sleepSeconds * 1_000_000);
            }
        }

        throw ApiException::invalidResponse(
            $this->provider(),
            sprintf('LinkedIn video processing timed out for [%s].', $videoUrn),
        );
    }

    private function videoStatusPollAttempts(): int
    {
        $configured = $this->providerConfig['video_status_poll_attempts'] ?? 20;

        if (is_int($configured))
        {
            return max(1, $configured);
        }

        if (is_string($configured) && preg_match('/^-?\d+$/', $configured) === 1)
        {
            return max(1, (int)$configured);
        }

        return 20;
    }

    private function videoStatusPollSleepSeconds(): int
    {
        $configured = $this->providerConfig['video_status_poll_sleep_seconds'] ?? 2;

        if (is_int($configured))
        {
            return max(0, $configured);
        }

        if (is_string($configured) && preg_match('/^-?\d+$/', $configured) === 1)
        {
            return max(0, (int)$configured);
        }

        return 2;
    }
}
