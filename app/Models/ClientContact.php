<?php

namespace App\Models;

use App\Domain\Clients\RelationshipType;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'relationship', 'relationship_type', 'phone', 'email', 'is_emergency_contact', 'notes', 'sort'])]
class ClientContact extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return ['is_emergency_contact' => 'boolean', 'relationship_type' => RelationshipType::class];
    }

    /** What the contact list shows: the typed label ("Mother") or, without one, the kind ("Parent"). */
    public function relationshipLabel(): ?string
    {
        return filled($this->relationship) ? $this->relationship : $this->relationship_type?->label();
    }

    public function isGuardian(): bool
    {
        return $this->relationship_type?->isGuardian() ?? false;
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
