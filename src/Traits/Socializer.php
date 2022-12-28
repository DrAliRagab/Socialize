<?php

namespace DrAliRagab\Socialize\Traits;

use DrAliRagab\Socialize\Providers\Facebook;
use DrAliRagab\Socialize\Providers\Instagram;
use DrAliRagab\Socialize\Providers\Twitter;

trait Socializer
{
    private function getParameters(array $config = []): array
    {
        $messageColumn = config('socialize.model_columns.message_column', 'title');
        $imageColumn = config('socialize.model_columns.photo_column', 'image');

        $message = $config['message'] ?? $this->$messageColumn ?? null;
        $photo = $config['photo'] ?? $this->$imageColumn ?? null;
        $options = $config['options'] ?? [];

        return [$message, $photo, $options];
    }

    /**
     * Share to Facebook
     */
    public function shareToFacebook(array $config = []): Facebook
    {
        $facebook = new Facebook($config['config'] ?? 'default');

        [$message, $photo, $options] = $this->getParameters($config);

        return $facebook->share($message, $photo, $options);
    }

    /**
     * Share to Twitter
     */
    public function shareToTwitter(array $config = []): Twitter
    {
        $twitter = new Twitter($config['config'] ?? 'default');

        [$message, $photo, $options] = $this->getParameters($config);

        return $twitter->share($message, $photo, $options);
    }

    /**
     * Share to Instagram
     */
    public function shareToInstagram(array $config = []): Instagram
    {
        $instagram = new Instagram($config['config'] ?? 'default');

        [$message, $photo, $options] = $this->getParameters($config);

        return $instagram->share($message, $photo, $options);
    }
}
