<?php

namespace App\Domain\Clients;

use App\Domain\Identity\PermissionRegistry;
use App\Domain\Identity\PermissionResolver;
use App\Domain\Tenancy\TenantMismatch;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\TimelineEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a client's timeline: newest first, keyset-paginated on
 * (occurred_at, id) so a page costs the same however deep it is and entries
 * written meanwhile never shift it, and filtered to the categories the
 * reader is allowed to see.
 *
 * Categories are a permission boundary. Administrative and scheduling
 * entries are visible to anyone who can view the client. The rest carry
 * content that needs its own permission, and those permissions do not exist
 * yet, so they are hidden from everyone. EXTENSION POINT: when a module adds
 * its permission to PermissionRegistry, put its key in GATED_CATEGORIES;
 * holders of the key then see that category (nothing else here changes).
 */
final class ClientTimelineReader
{
    public const PAGE_SIZE = 30;

    /** Visible to every reader who can view the client. */
    public const OPEN_CATEGORIES = [Timeline::ADMINISTRATIVE, Timeline::SCHEDULING];

    /**
     * Category => the permission key that unlocks it, or null while no such
     * permission exists (hidden from everyone).
     *
     * @var array<string, ?string>
     */
    public const GATED_CATEGORIES = [
        Timeline::CLINICAL => null,      // Phase 4: clinical records
        Timeline::FINANCIAL => null,     // Phase 6: billing
        Timeline::PROGRAM => null,       // Phase 5: programs
        Timeline::DOCUMENT => null,      // Phase 3: forms & documents
        Timeline::COMMUNICATION => null, // Phase 2: messaging
    ];

    private const CURSOR_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param  array<string, ?string>  $gated  category => permission key; defaults to GATED_CATEGORIES
     *                                         (injectable so the extension point can be exercised before a real permission exists)
     */
    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly array $gated = self::GATED_CATEGORIES,
    ) {}

    /**
     * Categories $viewer may read (none for a member who is no longer active).
     * Whether they may see the client at all is page()'s check.
     *
     * @return list<string>
     */
    public function visibleCategories(OrganizationMembership $viewer): array
    {
        if (! $viewer->isActive()) {
            return [];
        }

        $categories = self::OPEN_CATEGORIES;

        foreach ($this->gated as $category => $permission) {
            if ($permission !== null && PermissionRegistry::exists($permission) && $this->permissions->membershipHas($viewer, $permission)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * One page, newest first. A reader who may not see the client gets a 404
     * (AuthorizationException) before anything is read, whatever the caller
     * checked: every by-id path applies the same rule as the list.
     *
     * @param  string|null  $cursor  the opaque "before" cursor of the previous page; an unreadable one starts from the newest
     *
     * @throws TenantMismatch
     * @throws AuthorizationException
     */
    public function page(Client $client, OrganizationMembership $viewer, ?string $cursor = null): TimelinePage
    {
        if ($viewer->organization_id !== $client->organization_id) {
            throw new TenantMismatch('The viewer does not belong to the client\'s organization.');
        }

        if (! ClientVisibility::allows($client, $viewer)) {
            Response::denyAsNotFound()->authorize();
        }

        $query = TimelineEntry::query()
            ->select(['id', 'occurred_at', 'category', 'type', 'summary', 'actor_user_id', 'metadata'])
            ->where('client_id', $client->id)
            ->whereIn('category', $this->visibleCategories($viewer))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(self::PAGE_SIZE + 1)
            ->with('actor:id,name');

        if ($before = $this->decode($cursor)) {
            // Row-value comparison: PostgreSQL walks the (organization, client, occurred_at, id) index from that point.
            $query->whereRaw('(occurred_at, id) < (?::timestamptz, ?::uuid)', [$before['at'], $before['id']]);
        }

        $entries = $query->get();
        $hasMore = $entries->count() > self::PAGE_SIZE;
        $entries = $entries->take(self::PAGE_SIZE)->values();

        return new TimelinePage($entries, $hasMore ? $this->encode($entries->last()) : null);
    }

    /**
     * Icon (a name from the ui icon set) and label of a category. The label
     * accompanies the icon everywhere: meaning is never carried by an icon alone.
     *
     * @return array{icon: string, label: string}
     */
    public static function presentation(string $category): array
    {
        return match ($category) {
            Timeline::ADMINISTRATIVE => ['icon' => 'user', 'label' => 'Record'],
            Timeline::SCHEDULING => ['icon' => 'calendar', 'label' => 'Scheduling'],
            Timeline::CLINICAL => ['icon' => 'activity', 'label' => 'Clinical'],
            Timeline::FINANCIAL => ['icon' => 'credit-card', 'label' => 'Billing'],
            Timeline::COMMUNICATION => ['icon' => 'message', 'label' => 'Messages'],
            Timeline::PROGRAM => ['icon' => 'layers', 'label' => 'Program'],
            Timeline::DOCUMENT => ['icon' => 'file', 'label' => 'Document'],
            default => ['icon' => 'info', 'label' => ucfirst($category)],
        };
    }

    private function encode(TimelineEntry $last): string
    {
        $payload = json_encode([$last->occurred_at->format(self::CURSOR_FORMAT), $last->id], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    /** @return array{at: string, id: string}|null */
    private function decode(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '' || strlen($cursor) > 200) {
            return null;
        }

        try {
            $payload = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true, 3, JSON_THROW_ON_ERROR);

            if (! is_array($payload) || ! array_is_list($payload) || count($payload) !== 2) {
                return null;
            }

            [$at, $id] = $payload;

            if (! is_string($at) || ! is_string($id) || ! Str::isUuid($id)) {
                return null;
            }

            // Validates the timestamp; the original string (with microseconds) is what is compared.
            if (CarbonImmutable::createFromFormat(self::CURSOR_FORMAT, $at) === false) {
                return null;
            }

            return ['at' => $at, 'id' => $id];
        } catch (Throwable) {
            return null;
        }
    }
}
