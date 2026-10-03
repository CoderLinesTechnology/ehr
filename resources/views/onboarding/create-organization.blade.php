<x-layouts.minimal title="Set up your practice">
    <div class="stack stack--lg">
        @if ($registrationClosed)
            <x-ui.empty-state icon="building" title="New organizations are set up by our team"
                description="Self-service sign-up is currently closed. If your organization has invited you, open the invitation link from your email. Otherwise contact platform support.">
            </x-ui.empty-state>
        @else
            <x-ui.page-header title="Set up your practice"
                description="Tell us the basics. You can change all of this later in Settings." />

            @if ($requiresApproval)
                <x-ui.alert tone="info" title="Approval required">
                    New organizations are reviewed by the platform team before they go live. We'll email you once yours is approved.
                </x-ui.alert>
            @endif

            <x-ui.card>
                <form method="POST" action="{{ route('onboarding.organization.store') }}" class="form" data-submit-once>
                    @csrf

                    <x-ui.field label="Practice or organization name" name="name" :required="true">
                        <x-ui.input name="name" autocomplete="organization" autofocus />
                    </x-ui.field>

                    <div class="form-grid">
                        <x-ui.field label="Country" name="country_code" :required="true">
                            <x-ui.select name="country_code" :options="$countries" :value="$defaults['country_code']" />
                        </x-ui.field>

                        <x-ui.field label="Currency" name="currency" :required="true">
                            <x-ui.select name="currency" :options="$currencies" :value="$defaults['currency']" />
                        </x-ui.field>
                    </div>

                    <x-ui.field label="Timezone" name="timezone" :required="true" help="Appointment times are shown in this timezone unless a location has its own.">
                        <x-ui.select name="timezone" :options="$timezones" :value="$defaults['timezone']" />
                    </x-ui.field>

                    <x-ui.field label="Phone" name="phone" :optional="true">
                        <x-ui.input name="phone" type="tel" autocomplete="tel" />
                    </x-ui.field>

                    <div class="form-actions">
                        <x-ui.button type="submit" icon-right="arrow-right">Create organization</x-ui.button>
                    </div>
                </form>
            </x-ui.card>

            <p class="text-muted text-sm">
                You'll be the organization administrator. Client records you add belong to your organization and are never visible to anyone outside it.
            </p>
        @endif
    </div>
</x-layouts.minimal>
