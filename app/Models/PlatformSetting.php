<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Raw storage; read and write through SettingsService, never directly. */
#[Table(key: 'key', keyType: 'string', incrementing: false)]
class PlatformSetting extends Model
{
    public const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
