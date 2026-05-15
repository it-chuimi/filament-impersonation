<?php

declare(strict_types=1);

namespace Chuimi\FilamentImpersonation\Tests\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $table   = 'users';
    protected $guarded = [];
    public    $timestamps = true;
}
