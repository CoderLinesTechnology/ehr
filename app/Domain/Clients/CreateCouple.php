<?php

namespace App\Domain\Clients;

use App\Domain\Saas\LimitReached;
use App\Domain\Shared\DomainException;
use App\Domain\Tenancy\TenantContext;
use App\Models\Client;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Register a couple: a client record of type couple (its own number, status, billing, appointments) linked to
 * its two members. Each member is either an EXISTING client the actor may see, or a NEW person created in the
 * same transaction (first and last name, an e-mail and/or phone, and whatever the organization requires of a
 * new client). Everything is written or nothing is.
 *
 * The couple's name is derived from its members (CoupleMembers::name) and can be edited later like any name.
 * New members inherit the couple's primary clinician, primary location (or virtual) and billing type.
 *
 * Input:
 *   partners => [key => ['mode' => 'existing', 'client_id' => uuid]
 *                     | ['mode' => 'new', 'first_name', 'last_name', 'email', 'phone', 'date_of_birth']] (exactly two)
 *   emails / phones (the couple's shared ones, optional), billing_type, primary_location_id (id or "virtual"),
 *   primary_clinician_membership_id, address, preferred_contact_method, referral_source, administrative_notes.
 */
final class CreateCouple
{
    /** What a couple record keeps of the client form (no date of birth, sex, pronouns...: those belong to people). */
    private const COUPLE_FIELDS = [
        'preferred_contact_method', 'address_line1', 'address_line2', 'city', 'region', 'postal_code', 'country_code',
        'timezone', 'primary_clinician_membership_id', 'primary_location_id', 'is_virtual', 'billing_type',
        'referral_source', 'administrative_notes', 'emails', 'phones', 'primary_email', 'primary_phone', 'email', 'phone',
    ];

    /** What a NEW member is created from. */
    private const MEMBER_FIELDS = ['first_name', 'last_name', 'email', 'phone', 'date_of_birth'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CreateClient $createClient,
        private readonly ClientAttributes $attributes,
        private readonly ClientContactPoints $contactPoints,
        private readonly ClientRequirements $requirements,
        private readonly CoupleMembers $members,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @param  OrganizationMembership|null  $viewer  whose visibility decides which existing clients may be linked
     *                                               (default: the current member; none = no existing client)
     * @return Client the couple record
     *
     * @throws DomainException
     * @throws LimitReached
     */
    public function __invoke(array $input, ?User $actor = null, ?OrganizationMembership $viewer = null): Client
    {
        $organization = $this->tenant->organizationOrFail();
        $viewer ??= $this->tenant->membership();

        $coupleInput = Arr::only($input, self::COUPLE_FIELDS);
        $coupleData = ($this->attributes)($coupleInput, $organization);
        $couplePoints = $this->contactPoints->fromInput($coupleInput, $organization);

        $partners = is_array($input['partners'] ?? null) ? $input['partners'] : [];
        if (count($partners) !== CoupleMembers::SIZE) {
            throw new DomainException('A couple has two members: choose or add both partners.', 'couple_needs_two', 'partners');
        }

        // Check everything before writing anything: each partner is an existing client or a complete new one.
        $plan = [];
        foreach ($partners as $key => $partner) {
            $prefix = "partners.{$key}.";
            $partner = is_array($partner) ? $partner : [];
            $mode = ($partner['mode'] ?? 'existing') === 'new' ? 'new' : 'existing';

            if ($mode === 'existing') {
                $plan[] = ['client' => $this->members->existing($partner['client_id'] ?? null, $prefix.'client_id', $viewer)];

                continue;
            }

            $memberInput = Arr::only($partner, self::MEMBER_FIELDS) + [
                'primary_clinician_membership_id' => $coupleInput['primary_clinician_membership_id'] ?? null,
                'primary_location_id' => ($coupleData['is_virtual'] ?? false) ? ClientAttributes::VIRTUAL : ($coupleData['primary_location_id'] ?? null),
                'billing_type' => $coupleData['billing_type'] ?? BillingType::SelfPay->value,
            ];
            $plan[] = self::prefixed($prefix, function () use ($memberInput, $organization) {
                foreach (['first_name' => 'first name', 'last_name' => 'last name'] as $name => $label) {
                    if (! is_string($memberInput[$name] ?? null) || trim($memberInput[$name]) === '') {
                        throw new DomainException("Enter the partner's {$label}.", $name.'_required', $name);
                    }
                }
                $data = ($this->attributes)($memberInput, $organization);
                $points = $this->contactPoints->fromInput($memberInput, $organization);
                $this->requirements->assertSatisfied($organization, $data + CreateClient::primaries($points));

                return ['data' => $data, 'points' => $points];
            });
        }

        if (isset($plan[0]['client'], $plan[1]['client'])) {
            CoupleMembers::assertDistinct($plan[0]['client'], $plan[1]['client'], 'partners.'.array_keys($partners)[1].'.client_id');
        }

        $names = array_map(fn (array $p) => isset($p['client'])
            ? [$p['client']->first_name, $p['client']->last_name]
            : [trim($p['data']['first_name']), trim($p['data']['last_name'])], $plan);
        $coupleData += CoupleMembers::name($names[0][0], $names[0][1], $names[1][0], $names[1][1]);

        // "Contact required": the couple is reachable through its own e-mails/phones or any member's.
        $reachable = CreateClient::primaries($couplePoints);
        foreach ($plan as $p) {
            $member = isset($p['client']) ? ['email' => $p['client']->email, 'phone' => $p['client']->phone] : CreateClient::primaries($p['points']);
            $reachable['email'] ??= $member['email'];
            $reachable['phone'] ??= $member['phone'];
        }
        $this->requirements->assertSatisfied($organization, $coupleData + $reachable, person: false);

        return DB::transaction(function () use ($plan, $coupleData, $couplePoints, $actor) {
            $people = [];
            foreach ($plan as $p) {
                $people[] = $p['client'] ?? $this->createClient->register($p['data'], ClientType::Adult, $p['points'], $actor);
            }

            return $this->createClient->register($coupleData, ClientType::Couple, $couplePoints, $actor, [], function (Client $couple) use ($people, $actor) {
                foreach ($people as $person) {
                    $this->members->link($couple, $person, $actor);
                }
            });
        });
    }

    /**
     * Run $check, re-pointing a field-level refusal at the partner's own fields ("phone" → "partners.1.phone").
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     *
     * @throws DomainException
     */
    private static function prefixed(string $prefix, Closure $check): mixed
    {
        try {
            return $check();
        } catch (LimitReached $e) {
            throw $e;
        } catch (DomainException $e) {
            throw new DomainException($e->userMessage(), $e->errorCode(), $prefix.($e->field() ?? 'first_name'));
        }
    }
}
