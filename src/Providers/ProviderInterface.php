<?php

namespace DrAliRagab\Socialize\Providers;

interface ProviderInterface
{
    /**
     * Share to provider
     *
     * @param  string|null  $message
     * @param  string|null  $image
     * @param  array|null  $options
     * @return ProviderInterface
     */
    public function share(?string $message = null, ?string $image = null, ?array $options = null): ProviderInterface;

    /**
     * get post Id
     */
    public function getPostId(): ?string;
}
