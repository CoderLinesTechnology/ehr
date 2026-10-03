<?php

namespace App\Models;

use App\Domain\Clients\ContactPointKind;
use App\Domain\Clients\ContactPointLabel;
use App\Domain\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One e-mail address or phone number of a client. Written by the Clients domain only (ClientContactPoints);
 * the primary one of each kind is mirrored on clients.email / clients.phone, and every value feeds
 * clients.contact_search (a database trigger) so client search finds it.
 */
#[Fillable(['kind', 'value', 'label', 'is_primary', 'sort'])]
class ClientContactPoint extends Model
{
    use BelongsToOrganization, HasUuids;

    protected function casts(): array
    {
        return [
            'kind' => ContactPointKind::class,
            'label' => ContactPointLabel::class,
            'is_primary' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
