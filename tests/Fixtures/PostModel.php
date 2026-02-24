<?php

declare(strict_types=1);

namespace DrAliRagab\Socialize\Tests\Fixtures;

use DrAliRagab\Socialize\Concerns\CanShareSocially;
use Illuminate\Database\Eloquent\Model;

final class PostModel extends Model
{
    use CanShareSocially;

    protected $guarded = [];
}
