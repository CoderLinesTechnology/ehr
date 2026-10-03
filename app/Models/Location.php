<?php

namespace App\Models;

use App\Domain\Tenancy\BelongsToOrganization;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country_code',
    'phone', 'email', 'timezone', 'business_hours', 'sort',
])] // is_active is written by ChangeLocationStatus only
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToOrganization, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'business_hours' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayAddress(): string
    {
        return collect([$this->address_line1, $this->address_line2, $this->city, $this->region, $this->postal_code])
            ->filter()->implode(', ');
    }
}
