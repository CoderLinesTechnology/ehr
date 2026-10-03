<x-layouts.minimal :title="$organization->name">
    @php($status = $organization->status)
    <div class="status-page">
        @if ($status === \App\Domain\Platform\OrganizationStatus::Pending)
            <x-ui.empty-state icon="clock" :title="$organization->name.' is awaiting approval'"
                description="Thanks for signing up. The platform team reviews new organizations before they go live — you'll receive an email as soon as yours is approved.">
                @if (Route::has('account.profile'))
                    <x-slot:actions>
                        <x-ui.button variant="secondary" :href="route('account.profile')" icon="user">Your account</x-ui.button>
                    </x-slot:actions>
                @endif
            </x-ui.empty-state>
        @elseif ($status === \App\Domain\Platform\OrganizationStatus::Suspended)
            <x-ui.empty-state icon="lock" :title="$organization->name.' is suspended'"
                description="Access to this organization has been paused by the platform team. Records are kept safe. Please contact your organization administrator or platform support.">
            </x-ui.empty-state>
        @else
            <x-ui.empty-state icon="archive" :title="$organization->name.' is no longer available'"
                description="This organization has been closed. If you believe this is a mistake, contact platform support.">
            </x-ui.empty-state>
        @endif
    </div>
</x-layouts.minimal>
