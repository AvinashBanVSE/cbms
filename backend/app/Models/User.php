<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

final class User extends Authenticatable
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $hidden = ['password'];
}
