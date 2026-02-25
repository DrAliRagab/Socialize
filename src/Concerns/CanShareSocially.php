<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Concerns;

use DrAliRagab\Socialize\SocializeManager;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use function is_string;

trait CanShareSocially
{
    public function shareTo(string $provider, ?string $profile = null): ShareResult
    {
        /** @var SocializeManager $socializeManager */
        $socializeManager = app(SocializeManager::class);

        $messageColumn = config('socialize.model_columns.message', 'title');
        $linkColumn    = config('socialize.model_columns.link', 'url');
        $imageColumn   = config('socialize.model_columns.image', 'image');
        $videoColumn   = config('socialize.model_columns.video', 'video');

        $messageColumn = is_string($messageColumn) ? $messageColumn : 'title';
        $linkColumn    = is_string($linkColumn) ? $linkColumn : 'url';
        $imageColumn   = is_string($imageColumn) ? $imageColumn : 'image';
        $videoColumn   = is_string($videoColumn) ? $videoColumn : 'video';

        return $socializeManager
            ->provider($provider, $profile)
            ->message($this->resolveStringValue($messageColumn))
            ->link($this->resolveStringValue($linkColumn))
            ->imageUrl($this->resolveStringValue($imageColumn))
            ->videoUrl($this->resolveStringValue($videoColumn))
            ->share()
        ;
    }

    public function shareToFacebook(?string $profile = null): ShareResult
    {
        return $this->shareTo('facebook', $profile);
    }

    public function shareToInstagram(?string $profile = null): ShareResult
    {
        return $this->shareTo('instagram', $profile);
    }

    public function shareToTwitter(?string $profile = null): ShareResult
    {
        return $this->shareTo('twitter', $profile);
    }

    public function shareToLinkedIn(?string $profile = null): ShareResult
    {
        return $this->shareTo('linkedin', $profile);
    }

    private function resolveStringValue(string $column): ?string
    {
        $value = data_get($this, $column);

        if (! is_string($value))
        {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }
}
