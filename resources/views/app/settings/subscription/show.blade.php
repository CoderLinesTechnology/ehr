<x-layouts.app title="Subscription & usage">
    @include('app.settings.partials.open', ['current' => 'subscription'])

    <div class="set-stack">
        <section class="set-card" aria-labelledby="plan-title">
            <div class="set-card__head">
                <span class="set-card__icon" style="--tile: 35px; --gap: 14px" aria-hidden="true"><x-ui.icon name="credit-card" :size="20" /></span>
                <div class="set-card__text"><h3 class="set-card__title" id="plan-title">{{ $subscription?->plan?->name ?? 'No active plan' }}</h3>
                    @if ($subscription)<p class="set-card__sub">{{ $subscription->plan?->description }}</p>@endif
                </div>
                @if ($subscription)<x-ui.badge :tone="$subscription->status->tone()" style="margin-left: auto">{{ ucfirst(str_replace('_', ' ', $subscription->status->value)) }}</x-ui.badge>@endif
            </div>
            @if ($subscription)
                <dl class="set-dl">
                    @if ($subscription->status->value === 'trialing' && $subscription->trial_ends_at)<div><dt>Trial ends</dt><dd>{{ fmt()->date($subscription->trial_ends_at) }}</dd></div>@endif
                    @if ($subscription->current_period_ends_at)<div><dt>{{ $subscription->cancel_at_period_end ? 'Ends on' : 'Renews on' }}</dt><dd>{{ fmt()->date($subscription->current_period_ends_at) }}</dd></div>@endif
                    @if ($subscription->grace_ends_at)<div><dt>Grace period ends</dt><dd>{{ fmt()->date($subscription->grace_ends_at) }}</dd></div>@endif
                </dl>
            @else
                <p class="set-note" style="margin-top: 14px">This organization has no active subscription. Contact support to choose a plan.</p>
            @endif
            <p class="set-muted" style="margin-top: 14px">Plan changes are made by WellNest support.</p>
        </section>

        <section class="set-card" aria-labelledby="usage-title">
            <h3 class="set-card__title" id="usage-title">Usage</h3>
            <ul class="set-usage">
                @foreach ($limits as $limit)
                    <li>
                        <div class="set-usage__head"><span>{{ $limit['name'] }}</span><span>{{ $limit['used'] }} of {{ $limit['limit'] ?? 'unlimited' }}</span></div>
                        @if ($limit['limit'] !== null)
                            <div class="set-meter @if ($limit['percent'] >= 100) is-full @endif" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $limit['percent'] }}" aria-label="{{ $limit['name'] }}"><span style="width: {{ $limit['percent'] }}%"></span></div>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>

        <section class="set-card" aria-labelledby="modules-title">
            <h3 class="set-card__title" id="modules-title">Included in your plan</h3>
            <ul class="set-modules">
                @foreach ($modules as $module)
                    <li class="{{ $module['on'] ? '' : 'is-off' }}"><x-ui.icon :name="$module['on'] ? 'check' : 'minus'" :size="16" /><span>{{ $module['name'] }}<span class="sr-only">{{ $module['on'] ? ' (included)' : ' (not included)' }}</span></span></li>
                @endforeach
            </ul>
        </section>
    </div>
    @include('app.settings.partials.close')
</x-layouts.app>
