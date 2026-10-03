<x-layouts.platform title="New organization">
    @include('platform.partials.assets')
    <x-ui.page-header title="New organization" description="Create the practice, choose its plan and invite its owner. The owner receives an email to set a password and sign in.">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Organizations', 'url' => route('platform.organizations.index')], ['label' => 'New organization']]" />
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form method="POST" action="{{ route('platform.organizations.store') }}" class="form pf-form" data-submit-once>
        @csrf
        <x-ui.card title="Profile">
            @include('platform.organizations._profile-fields', ['creating' => true, 'values' => ['country_code' => $defaults['country_code'], 'timezone' => $defaults['timezone'], 'currency' => $defaults['currency']]])
        </x-ui.card>
        <x-ui.card title="Plan and owner">
            <div class="form-grid">
                <x-ui.field label="Plan" name="plan_id" :required="true">
                    <x-ui.select name="plan_id" placeholder="Choose a plan" required
                        :options="$plans->mapWithKeys(fn ($p) => [$p->id => $p->name.' - '.fmt()->money($p->price_minor, $p->currency).' / '.$p->billing_interval])->all()" />
                </x-ui.field>
                <x-ui.field label="Starting status" name="status" :required="true" help="Trial starts the plan's trial period. Pending keeps the organization locked until it is activated.">
                    <x-ui.select name="status" value="trial" required :options="['trial' => 'Trial', 'active' => 'Active', 'pending' => 'Pending']" />
                </x-ui.field>
                <x-ui.field label="Owner email" name="owner_email" :required="true" class="form-grid__full" help="This person is invited as the organization's owner. They do not need an account yet.">
                    <x-ui.input type="email" name="owner_email" required maxlength="254" autocomplete="off" />
                </x-ui.field>
            </div>
        </x-ui.card>
        <div class="form-actions">
            <x-ui.button variant="secondary" :href="route('platform.organizations.index')">Cancel</x-ui.button>
            <x-ui.button type="submit" icon="send">Create and invite owner</x-ui.button>
        </div>
    </form>
</x-layouts.platform>
