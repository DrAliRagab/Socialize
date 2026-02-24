<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Contracts;

use DrAliRagab\Socialize\Enums\Provider;
use DrAliRagab\Socialize\ValueObjects\SharePayload;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

interface ProviderDriver
{
    public function provider(): Provider;

    public function share(SharePayload $payload): ShareResult;

    public function delete(string $postId): bool;
}
