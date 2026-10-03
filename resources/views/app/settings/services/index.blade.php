<x-layouts.app title="Services">
    @push('settings-actions')<x-ui.button icon="plus" :href="route('app.settings.services.create')">Add service</x-ui.button>@endpush
    @include('app.settings.partials.open', ['current' => 'services'])

    @if ($services->isEmpty())
        <x-ui.empty-state icon="layout-list" title="No services yet" description="Add what you offer, such as an initial consultation or a therapy session, with its length and price.">
            <x-slot:actions><x-ui.button icon="plus" :href="route('app.settings.services.create')">Add service</x-ui.button></x-slot:actions>
        </x-ui.empty-state>
    @else
        <section class="set-card set-card--flush" aria-label="Services">
            <ul class="set-list">
                @foreach ($services as $service)
                    <li>
                        <div class="set-list__main">
                            <p class="set-list__title">{{ $service->name }}
                                <x-ui.badge :tone="$service->is_active ? 'success' : 'neutral'">{{ $service->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                                @if ($service->allows_telehealth)<x-ui.badge tone="purple">Telehealth</x-ui.badge>@endif
                            </p>
                            <p class="set-list__meta">{{ $service->duration_minutes }} min · {{ $service->billing_behavior === 'billable' ? \App\Support\Money::format((int) $service->price_minor, $service->currency) : 'Not billed' }}@if ($service->code) · {{ $service->code }}@endif</p>
                        </div>
                        <div class="set-list__actions">
                            <x-ui.button variant="secondary" size="sm" icon="pencil" :href="route('app.settings.services.edit', ['service' => $service])">Edit</x-ui.button>
                            @if ($service->is_active)
                                <x-ui.confirm-form :action="route('app.settings.services.status', ['service' => $service])" method="PATCH" :title="'Switch off '.$service->name.'?'"
                                    message="It will no longer be offered when booking. Past appointments keep it." confirm-label="Switch off" button-label="Switch off" button-size="sm" button-variant="neutral">
                                    <input type="hidden" name="active" value="0">
                                </x-ui.confirm-form>
                            @else
                                <form method="POST" action="{{ route('app.settings.services.status', ['service' => $service]) }}" data-submit-once>
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="active" value="1">
                                    <x-ui.button type="submit" variant="neutral" size="sm">Switch on</x-ui.button>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @include('app.settings.partials.close')
</x-layouts.app>
