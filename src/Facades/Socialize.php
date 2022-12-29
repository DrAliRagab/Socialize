<?php

namespace DrAliRagab\Socialize\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \DrAliRagab\Socialize\Socialize
 * 
 */
class Socialize extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \DrAliRagab\Socialize\Socialize::class;
    }
}
