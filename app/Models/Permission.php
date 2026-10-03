<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Mirror of PermissionRegistry, kept in sync by `permissions:sync`. */
#[Table(key: 'key', keyType: 'string', incrementing: false)]
class Permission extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_sensitive' => 'boolean'];
    }
}
