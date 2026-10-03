@php
    use App\Domain\Saas\SubscriptionStatus;
    use App\Domain\Saas\SubscriptionTransitions;
    use App\Http\Requests\Platform\ChangeOrganizationStatusRequest;
    use App\Http\Requests\Platform\ChangeSubscriptionStatusRequest;
    use App\Http\Requests\Platform\RemoveEntitlementRequest;
    use App\Http\Requests\Platform\SetEntitlementRequest;

    $org = $overview->organization;
    $sub = $overview->subscription;
    $canLifecycle = Gate::allows('platform.organizations.lifecycle');
    $canSubscribe = Gate::allows('platform.subscriptions.manage');
    $canOverride = Gate::allows('platform.features.manage');
    $date = fn ($d) => $d ? fmt()->localDate($d, $timezone) : null;
    $planOptions = $plans->mapWithKeys(fn ($p) => [$p->id => $p->name.' - '.fmt()->money($p->price_minor, $p->currency).' / '.$p->billing_interval])->all();
@endphp
<x-layouts.platform :title="$org->name">
    @include('platform.partials.assets')
    <x-ui.page-header :title="$org->name" :description="'Address: '.$org->slug">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Organizations', 'url' => route('platform.organizations.index')], ['label' => $org->name]]" />
        </x-slot:breadcrumbs>
        <x-slot:meta>
            <div class="pf-meta">
                <x-ui.badge :tone="$org->status->tone()" :dot="true">{{ $org->status->label() }}</x-ui.badge>
                @if ($sub)<span>{{ $sub->plan->name }} plan</span>@endif
                <span>Created {{ $date($org->created_at) }}</span>
            </div>
        </x-slot:meta>
        <x-slot:actions>
            @can('platform.organizations.manage')
                <x-ui.button variant="secondary" icon="pencil" :href="route('platform.organizations.edit', $org)">Edit profile</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    <div class="pf-stack">
        <section aria-label="Usage against limits" class="pf-usage">
            @foreach ($overview->usage as $row)
                <div @class(['pf-usage__item', 'pf-usage__item--over' => $row['over_limit']])>
                    <span class="pf-usage__label">{{ $row['label'] }}</span>
                    <span class="pf-usage__value">{{ number_format($row['used']) }} <span class="pf-usage__limit">of {{ $row['limit'] === null ? 'unlimited' : number_format($row['limit']) }}</span></span>
                    @if ($row['limit'] !== null && $row['limit'] > 0)
                        <x-ui.progress :value="$row['used']" :max="$row['limit']" :show-value="false" :aria-label="$row['label']" />
                    @endif
                    @if ($row['over_limit'])<x-ui.badge tone="danger">Over the limit</x-ui.badge>@endif
                </div>
            @endforeach
            <div class="pf-usage__item">
                <span class="pf-usage__label">Appointments, last 30 days</span>
                <span class="pf-usage__value">{{ number_format($overview->appointmentsLast30Days) }}</span>
                <span class="pf-note">A count only. No client details are shown on any platform screen.</span>
            </div>
        </section>

        <div class="pf-split">
            <div class="pf-stack">
                {{-- Subscription --}}
                <x-ui.card title="Subscription" :padded="false">
                    @if ($sub)
                        <div class="pf-section">
                            <x-ui.dl>
                                <x-ui.dl-item label="Plan">{{ $sub->plan->name }}</x-ui.dl-item>
                                <x-ui.dl-item label="Status"><x-ui.badge :tone="$sub->status->tone()">{{ $sub->status->label() }}</x-ui.badge>@if ($sub->cancel_at_period_end) <span class="text-muted text-sm">Cancels at period end</span>@endif</x-ui.dl-item>
                                <x-ui.dl-item label="Price">{{ fmt()->money($sub->price_minor, $sub->currency) }} / {{ $sub->billing_interval }}</x-ui.dl-item>
                                <x-ui.dl-item label="Trial ends">{{ $date($sub->trial_ends_at) }}</x-ui.dl-item>
                                <x-ui.dl-item label="Current period ends">{{ $date($sub->current_period_ends_at) }}</x-ui.dl-item>
                                <x-ui.dl-item label="Grace period ends">{{ $date($sub->grace_ends_at) }}</x-ui.dl-item>
                            </x-ui.dl>
                            @if ($canSubscribe)
                                <div class="pf-actions">
                                    <x-ui.button variant="secondary" icon="repeat" data-dialog-open="change-plan">Change plan</x-ui.button>
                                    @foreach ($overview->subscriptionTransitions as $to)
                                        @php $destructive = SubscriptionTransitions::isDestructive($to); @endphp
                                        <x-ui.confirm-form :action="route('platform.organizations.subscription.status', $org)"
                                            :title="SubscriptionTransitions::actionLabel($to).'?'"
                                            :message="$destructive ? 'This can end '.$org->name.'\'s access to the paid features.' : 'The change is recorded in the subscription history.'"
                                            :tone="$destructive ? 'danger' : 'primary'" :button-variant="$destructive ? 'danger' : 'secondary'"
                                            :button-label="SubscriptionTransitions::actionLabel($to)" :confirm-label="SubscriptionTransitions::actionLabel($to)"
                                            reason-field="reason" :reason-required="true" :bag="ChangeSubscriptionStatusRequest::bagFor($to)">
                                            <input type="hidden" name="status" value="{{ $to->value }}">
                                            @if ($to === SubscriptionStatus::Grace)
                                                <x-ui.field label="Grace period ends" name="grace_ends_at" :optional="true" :bag="ChangeSubscriptionStatusRequest::bagFor($to)" help="Leave empty to use the platform default.">
                                                    <x-ui.input type="date" name="grace_ends_at" :bag="ChangeSubscriptionStatusRequest::bagFor($to)" />
                                                </x-ui.field>
                                            @endif
                                        </x-ui.confirm-form>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="pf-section">
                            <x-ui.alert tone="warning" title="No live subscription">This organization has no plan, so every limit is zero and paid modules are off. Start a subscription to restore access.</x-ui.alert>
                            @if ($canSubscribe)
                                <form method="POST" action="{{ route('platform.organizations.subscription.start', $org) }}" class="form" data-submit-once>
                                    @csrf
                                    <div class="form-grid">
                                        <x-ui.field label="Plan" name="plan_id" bag="subscription_start" :required="true"><x-ui.select name="plan_id" bag="subscription_start" placeholder="Choose a plan" :options="$planOptions" required /></x-ui.field>
                                        <x-ui.field label="Reason" name="reason" bag="subscription_start" :optional="true"><x-ui.input name="reason" bag="subscription_start" maxlength="500" /></x-ui.field>
                                    </div>
                                    <x-ui.checkbox name="trial" label="Start with the plan's trial period" :checked="true" />
                                    <div class="form-actions"><x-ui.button type="submit">Start subscription</x-ui.button></div>
                                </form>
                            @endif
                        </div>
                    @endif

                    @if ($overview->subscriptionHistory->isNotEmpty())
                        <div class="pf-section">
                            <h3 class="pf-section__title">Subscription history</h3>
                        </div>
                        <x-ui.table :compact="true" label="Subscription history">
                            <thead><tr><th scope="col">When</th><th scope="col">Change</th><th scope="col">Reason</th><th scope="col">By</th></tr></thead>
                            <tbody>
                                @foreach ($overview->subscriptionHistory as $h)
                                    <tr>
                                        <td class="tabular nowrap">{{ fmt()->dateTime($h->occurred_at, $timezone) }}</td>
                                        <td>
                                            @if ($h->event === 'started')
                                                Started on {{ $h->toPlan?->name }}@if ($h->to_status) ({{ \App\Domain\Saas\SubscriptionStatus::tryFrom($h->to_status)?->label() ?? $h->to_status }})@endif
                                            @elseif ($h->event === 'plan_changed')
                                                {{ $h->fromPlan?->name }} <span class="pf-arrow">&rarr;</span> {{ $h->toPlan?->name }}
                                            @else
                                                {{ \App\Domain\Saas\SubscriptionStatus::tryFrom((string) $h->from_status)?->label() }} <span class="pf-arrow">&rarr;</span> {{ \App\Domain\Saas\SubscriptionStatus::tryFrom((string) $h->to_status)?->label() }}
                                            @endif
                                        </td>
                                        <td class="pf-reason">{{ $h->reason }}</td>
                                        <td>{{ $h->actor?->name ?? 'System' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ui.table>
                    @endif
                </x-ui.card>

                {{-- Entitlements --}}
                <x-ui.card title="Entitlements" description="What this organization may use right now: the plan's value, unless an override replaces it." :padded="false">
                    <x-ui.table label="Entitlements" class="pf-matrix">
                        <thead>
                            <tr>
                                <th scope="col">Feature</th>
                                <th scope="col">Plan</th>
                                <th scope="col">Override</th>
                                <th scope="col">Effective</th>
                                @if ($canOverride)<th scope="col" class="table__actions"><span class="sr-only">Actions</span></th>@endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($overview->entitlements as $row)
                                @php
                                    $isLimit = $row['type'] === 'limit';
                                    $fmtValue = function ($v) use ($isLimit, $row) {
                                        if ($isLimit) { return $v === null ? 'Unlimited' : number_format((int) $v).' '.($row['unit'] ?? ''); }
                                        return $v ? 'On' : 'Off';
                                    };
                                    $ov = $row['override'];
                                @endphp
                                <tr>
                                    <td class="pf-matrix__feature"><span class="pf-matrix__name">{{ $row['name'] }}</span><span class="pf-cell-sub">{{ $row['description'] }}</span></td>
                                    <td class="tabular">{{ $row['plan_has_value'] ? $fmtValue($row['plan_value']) : 'Not in plan' }}</td>
                                    <td>
                                        @if ($ov)
                                            <span class="tabular">{{ $ov['unlimited'] ? 'Unlimited' : $fmtValue($ov['value']) }}</span>
                                            @if ($ov['expired'])<x-ui.badge tone="neutral">Expired</x-ui.badge>@endif
                                            <span class="pf-override-note">{{ $ov['reason'] }}@if ($ov['expires_at']) &middot; until {{ $date($ov['expires_at']) }}@endif @if ($ov['granted_by']) &middot; {{ $ov['granted_by'] }}@endif</span>
                                        @else
                                            <span class="text-muted">None</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="pf-value tabular">{{ $fmtValue($row['effective']) }}</span>
                                        @if ($row['platform_disabled'])<span class="pf-override-note">Switched off for every organization (kill switch).</span>@endif
                                    </td>
                                    @if ($canOverride)
                                        <td class="table__actions">
                                            <x-ui.button variant="secondary" size="sm" :data-dialog-open="'ent-'.$row['key']">{{ $ov ? 'Change' : 'Override' }}</x-ui.button>
                                            @if ($ov)
                                                <x-ui.confirm-form :action="route('platform.organizations.entitlements.destroy', [$org, $row['key']])" method="DELETE"
                                                    :title="'Remove the override for '.$row['name'].'?'" message="The plan's own value applies again straight away."
                                                    button-label="Remove" button-size="sm" button-variant="neutral" confirm-label="Remove override"
                                                    reason-field="reason" :reason-required="true" :bag="RemoveEntitlementRequest::bagFor($row['key'])" />
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </x-ui.table>
                </x-ui.card>
            </div>

            <div class="pf-stack">
                {{-- Status --}}
                <x-ui.card title="Status">
                    <div class="pf-stack">
                        <x-ui.dl>
                            <x-ui.dl-item label="Current status"><x-ui.badge :tone="$org->status->tone()" :dot="true">{{ $org->status->label() }}</x-ui.badge></x-ui.dl-item>
                            @if ($org->status_reason)<x-ui.dl-item label="Reason" :wide="true">{{ $org->status_reason }}</x-ui.dl-item>@endif
                            <x-ui.dl-item label="Onboarding">{{ $overview->onboardingCompleted() ? 'Completed' : 'Not completed' }}</x-ui.dl-item>
                        </x-ui.dl>
                        @if ($canLifecycle && $overview->statusTransitions !== [])
                            <div class="pf-actions">
                                @foreach ($overview->statusTransitions as $to)
                                    <x-ui.confirm-form :action="route('platform.organizations.status', $org)"
                                        :title="$to->actionLabel().' '.$org->name.'?'"
                                        :message="$to->isRestrictive() ? 'Staff lose access to the workspace while it is '.mb_strtolower($to->label()).'. Their data is kept.' : 'Staff regain access to the workspace.'"
                                        :tone="$to->isRestrictive() ? 'danger' : 'primary'" :button-variant="$to->isRestrictive() ? 'danger' : 'secondary'"
                                        :button-label="$to->actionLabel()" :confirm-label="$to->actionLabel()"
                                        reason-field="reason" :reason-required="$to->isRestrictive()" :bag="ChangeOrganizationStatusRequest::bagFor($to)">
                                        <input type="hidden" name="status" value="{{ $to->value }}">
                                    </x-ui.confirm-form>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </x-ui.card>

                <x-ui.card title="Profile">
                    <x-ui.dl>
                        <x-ui.dl-item label="Legal name">{{ $org->legal_name }}</x-ui.dl-item>
                        <x-ui.dl-item label="Contact email">{{ $org->email }}</x-ui.dl-item>
                        <x-ui.dl-item label="Phone">{{ $org->phone }}</x-ui.dl-item>
                        <x-ui.dl-item label="Country">{{ \App\Support\Regions::countries()[$org->country_code] ?? $org->country_code }}</x-ui.dl-item>
                        <x-ui.dl-item label="Timezone">{{ $org->timezone }}</x-ui.dl-item>
                        <x-ui.dl-item label="Currency">{{ $org->currency }}</x-ui.dl-item>
                    </x-ui.dl>
                </x-ui.card>

                <x-ui.card title="Status history" :padded="false">
                    @if ($overview->statusHistory->isEmpty())
                        <p class="pf-note pf-note--pad">No status changes yet.</p>
                    @else
                        <x-ui.table :compact="true" label="Status history">
                            <thead><tr><th scope="col">Change</th><th scope="col">Reason and who</th></tr></thead>
                            <tbody>
                                @foreach ($overview->statusHistory as $h)
                                    <tr>
                                        <td>
                                            @if ($h->from_status){{ $h->from_status->label() }} <span class="pf-arrow">&rarr;</span> @endif<strong>{{ $h->to_status->label() }}</strong>
                                            <span class="pf-cell-sub">{{ fmt()->dateTime($h->occurred_at, $timezone) }}</span>
                                        </td>
                                        <td class="pf-reason">{{ $h->reason }}<span class="pf-cell-sub">{{ $h->actor?->name ?? 'System' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </x-ui.table>
                    @endif
                </x-ui.card>
            </div>
        </div>

        <x-ui.card title="Recent platform activity" description="Platform actions about this organization. What happens inside it is never shown here." :padded="false">
            @include('platform.partials.audit-table', ['entries' => $overview->recentAudit, 'timezone' => $timezone])
        </x-ui.card>
    </div>

    {{-- Dialogs --}}
    @if ($canSubscribe && $sub)
        <x-ui.modal id="change-plan" title="Change plan" size="sm" :open="$errors->getBag('subscription_plan')->any()" description="The new plan applies straight away. The change is kept in the subscription history.">
            <form method="POST" action="{{ route('platform.organizations.subscription.plan', $org) }}" id="change-plan-form" class="form" data-submit-once>
                @csrf
                <x-ui.field label="New plan" name="plan_id" bag="subscription_plan" :required="true"><x-ui.select name="plan_id" bag="subscription_plan" placeholder="Choose a plan" :options="$planOptions" required /></x-ui.field>
                <x-ui.field label="Reason" name="reason" bag="subscription_plan" :required="true"><x-ui.textarea name="reason" bag="subscription_plan" rows="3" required /></x-ui.field>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
                <x-ui.button type="submit" form="change-plan-form">Change plan</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($canOverride)
        @foreach ($overview->entitlements as $row)
            @php
                $bag = SetEntitlementRequest::bagFor($row['key']);
                $isLimit = $row['type'] === 'limit';
                $ov = $row['override'];
                $formId = 'ent-form-'.$row['key'];
            @endphp
            <x-ui.modal :id="'ent-'.$row['key']" :title="'Override: '.$row['name']" size="sm" :open="$errors->getBag($bag)->any()" description="Applies to this organization only, whatever its plan says. Kept in the audit log.">
                <form method="POST" action="{{ route('platform.organizations.entitlements.store', $org) }}" id="{{ $formId }}" class="form" data-submit-once>
                    @csrf
                    <input type="hidden" name="feature_key" value="{{ $row['key'] }}">
                    @if ($isLimit)
                        <x-ui.field :label="'Limit ('.$row['unit'].')'" name="limit_value" :bag="$bag" :id="$formId.'-limit'">
                            <x-ui.input type="number" name="limit_value" :id="$formId.'-limit'" :bag="$bag" min="0" :value="$ov && ! $ov['unlimited'] ? $ov['value'] : null" />
                        </x-ui.field>
                        <x-ui.checkbox name="unlimited" label="Unlimited" :checked="$ov['unlimited'] ?? false" :id="$formId.'-unl'" :bag="$bag" />
                    @else
                        <x-ui.field label="Feature" name="enabled" :required="true" :bag="$bag" :id="$formId.'-enabled'">
                            <x-ui.select name="enabled" :id="$formId.'-enabled'" :bag="$bag" :value="$ov ? ($ov['value'] ? '1' : '0') : ($row['effective'] ? '1' : '0')" :options="['1' => 'On', '0' => 'Off']" />
                        </x-ui.field>
                    @endif
                    <x-ui.field label="Expires on" name="expires_at" :optional="true" :bag="$bag" :id="$formId.'-exp'" help="Leave empty for no expiry. After this day the plan's value applies again by itself.">
                        <x-ui.input type="date" name="expires_at" :id="$formId.'-exp'" :bag="$bag" :value="$ov && $ov['expires_at'] ? $ov['expires_at']->setTimezone($timezone ?: config('app.timezone'))->format('Y-m-d') : null" />
                    </x-ui.field>
                    <x-ui.field label="Reason" name="reason" :required="true" :bag="$bag" :id="$formId.'-reason'">
                        <x-ui.textarea name="reason" :id="$formId.'-reason'" :bag="$bag" rows="3" required />
                    </x-ui.field>
                </form>
                <x-slot:footer>
                    <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
                    <x-ui.button type="submit" :form="$formId">Save override</x-ui.button>
                </x-slot:footer>
            </x-ui.modal>
        @endforeach
    @endif
</x-layouts.platform>
