<?php

namespace DrAliRagab\Socialize;

use DrAliRagab\Socialize\Providers\Facebook;
use DrAliRagab\Socialize\Providers\Twitter;
use DrAliRagab\Socialize\Providers\Instagram;

class Socialize
{
    /**
     * Facebook provider
     */
    public static function facebook(string $config = 'default'): Facebook
    {
        return new Facebook($config);
    }

    /**
     * Twitter provider
     */
    public static function twitter(string $config = 'default'): Twitter
    {
        return new Twitter($config);
    }

    /**
     * Instagram provider
     */
    public static function instagram(string $config = 'default'): Instagram
    {
        return new Instagram($config);
    }
}
