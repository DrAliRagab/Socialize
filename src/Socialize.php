<?php

namespace DrAliRagab\Socialize;

use DrAliRagab\Socialize\Providers\Provider;

class Socialize
{
    /**
     * Facebook provider
     */
    public static function facebook(string $config = 'default'): Provider
    {
        return new Providers\Facebook($config);
    }

    /**
     * Twitter provider
     */
    public static function twitter(string $config = 'default'): Provider
    {
        return new Providers\Twitter($config);
    }

    /**
     * Instagram provider
     */
    public static function instagram(string $config = 'default'): Provider
    {
        return new Providers\Instagram($config);
    }
}
