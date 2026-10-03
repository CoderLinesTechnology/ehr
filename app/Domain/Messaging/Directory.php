<?php

namespace App\Domain\Messaging;

use App\Domain\Clients\ClientStatus;
use App\Domain\Clients\ClientVisibility;
use App\Models\Client;
use App\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;

/** Who the member can start a conversation with (bounded lists for the "new conversation" panel). */
final class Directory
{
    public const LIMIT = 200;

    /** @return list<array{id: string, name: string, title: ?string}> active colleagues who may use team messaging */
    public function staff(OrganizationMembership $member): array
    {
        if (! ConversationAccess::permits($member, ConversationKind::Direct)) {
            return [];
        }

        return DB::table('organization_memberships as om')
            ->join('users as u', 'u.id', '=', 'om.user_id')
            ->where('om.organization_id', $member->organization_id)
            ->where('om.status', 'active')
            ->where('om.id', '<>', $member->id)
            ->whereExists(fn ($e) => $e->selectRaw('1')->from('membership_roles as mr')
                ->join('role_permissions as rp', 'rp.role_id', '=', 'mr.role_id')
                ->whereColumn('mr.membership_id', 'om.id')->where('mr.organization_id', $member->organization_id)
                ->where('rp.permission_key', 'messages.send'))
            ->orderBy('u.name')->limit(self::LIMIT)
            ->get(['om.id', 'u.name', 'om.title'])
            ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'title' => $r->title])->all();
    }

    /** @return list<array{id: string, name: string}> clients the member may see and message */
    public function clients(OrganizationMembership $member): array
    {
        if (! ConversationAccess::permits($member, ConversationKind::Client)) {
            return [];
        }

        return ClientVisibility::apply(Client::query(), $member)
            ->where('status', '<>', ClientStatus::Archived->value)
            ->orderBy('first_name')->orderBy('last_name')->limit(self::LIMIT)
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->fullName()])->all();
    }
}
