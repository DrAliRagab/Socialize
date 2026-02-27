<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Contracts;

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\ValueObjects\CommentResult;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

interface ProviderDriver
{
    public function provider(): Provider;

    public function share(SharePayload $sharePayload): ShareResult;

    public function comment(string $postId, string $message): CommentResult;

    public function delete(string $postId): bool;
}
