<?php

namespace DrAliRagab\Socialize;

use DrAliRagab\Socialize\Providers\Provider;

class Socialize
{
    /**
     * Start a new provider
     *
     * @param  string  $provider
     * @param  string  $config
     * @return Provider
     */
    private static function provider(string $provider, string $config = 'default'): Provider
    {
        $provider = __NAMESPACE__.'\\Providers\\'.ucfirst($provider);

        return new $provider($config);
    }

    /**
     * Start a new provider
     *
     * @param  string  $provider
     * @param  array  $args
     * @return Provider
     */
    public static function __callStatic(string $provider, array $args): Provider
    {
        $config = $args[0] ?? 'default';

        return self::provider($provider, $config);
    }
}
