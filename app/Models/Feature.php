<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Mirror of FeatureRegistry, kept in sync by `features:sync`. */
#[Table(key: 'key', keyType: 'string', incrementing: false)]
class Feature extends Model
{
    public const TYPE_BOOLEAN = 'boolean';

    public const TYPE_LIMIT = 'limit';

    protected $guarded = [];

    public function isLimit(): bool
    {
        return $this->type === self::TYPE_LIMIT;
    }
}
