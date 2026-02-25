<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Concerns;

use DrAliRagab\Socialize\SocializeManager;
use DrAliRagab\Socialize\Support\FluentShare;
use DrAliRagab\Socialize\ValueObjects\ShareResult;

use function is_array;
use function is_string;

trait CanShareSocially
{
    public function shareTo(string $provider, ?string $profile = null): ShareResult
    {
        return $this->shareBuilderTo($provider, $profile)->share();
    }

    public function shareBuilderTo(string $provider, ?string $profile = null): FluentShare
    {
        /** @var SocializeManager $socializeManager */
        $socializeManager = app(SocializeManager::class);

        $columns = $this->socializeColumns();

        return $socializeManager
            ->provider($provider, $profile)
            ->message($this->resolveStringValue($columns['message']))
            ->link($this->resolveStringValue($columns['link']))
            ->imageUrl($this->resolveStringValue($columns['image']))
            ->videoUrl($this->resolveStringValue($columns['video']))
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

    /**
     * @return array{message: string, link: string, image: string, video: string}
     */
    protected function socializeColumns(): array
    {
        $columns = config('socialize.model_columns', []);

        if (! is_array($columns))
        {
            $columns = [];
        }

        $messageColumn = $columns['message'] ?? 'title';
        $linkColumn    = $columns['link']    ?? 'url';
        $imageColumn   = $columns['image']   ?? 'image';
        $videoColumn   = $columns['video']   ?? 'video';

        return [
            'message' => is_string($messageColumn) ? $messageColumn : 'title',
            'link'    => is_string($linkColumn) ? $linkColumn : 'url',
            'image'   => is_string($imageColumn) ? $imageColumn : 'image',
            'video'   => is_string($videoColumn) ? $videoColumn : 'video',
        ];
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
