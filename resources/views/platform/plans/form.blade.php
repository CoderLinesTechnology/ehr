@php
    $editing = $plan !== null;
    $limitDefs = collect($features)->where('type', 'limit');
    $boolDefs = collect($features)->where('type', 'boolean');
    $v = fn ($key, $default = null) => $values[$key] ?? $default;
@endphp
<x-layouts.platform :title="$editing ? 'Edit '.$plan->name : 'New plan'">
    @include('platform.partials.assets')
    <x-ui.page-header :title="$editing ? 'Edit '.$plan->name : 'New plan'" :description="$editing ? 'Changes apply to '.number_format($liveSubscriptions).' organization'.($liveSubscriptions === 1 ? '' : 's').' on this plan, straight away.' : 'Set the price, then choose the modules and limits it includes.'">
        <x-slot:breadcrumbs>
            <x-ui.breadcrumbs :items="[['label' => 'Plans & features', 'url' => route('platform.plans.index')], ['label' => $editing ? $plan->name : 'New plan']]" />
        </x-slot:breadcrumbs>
    </x-ui.page-header>

    <form method="POST" action="{{ $editing ? route('platform.plans.update', $plan) : route('platform.plans.store') }}" class="form pf-form" data-submit-once>
        @csrf
        @if ($editing)@method('PUT')@endif

        <x-ui.card title="Plan">
            <div class="form-grid">
                <x-ui.field label="Name" name="name" :required="true"><x-ui.input name="name" :value="$v('name')" required maxlength="120" /></x-ui.field>
                @if ($editing)
                    <x-ui.field label="Key" name="key_display" help="Chosen when the plan was created and never changes."><x-ui.input name="key_display" :value="$plan->key" disabled /></x-ui.field>
                @else
                    <x-ui.field label="Key" name="key" :required="true" help="Lowercase letters, numbers, hyphens and underscores. It cannot be changed later."><x-ui.input name="key" required maxlength="40" autocapitalize="off" /></x-ui.field>
                @endif
                <x-ui.field label="Description" name="description" :optional="true" class="form-grid__full"><x-ui.textarea name="description" :value="$v('description')" rows="2" /></x-ui.field>
                <x-ui.field label="Price" name="price" :required="true" help="Per billing interval, for example 250 or 250.50."><x-ui.input name="price" :value="$v('price', '0')" inputmode="decimal" required /></x-ui.field>
                <x-ui.field label="Currency" name="currency" :required="true"><x-ui.select name="currency" :options="$currencies" :value="$v('currency', 'GHS')" required /></x-ui.field>
                <x-ui.field label="Billed" name="billing_interval" :required="true"><x-ui.select name="billing_interval" :options="['month' => 'Monthly', 'year' => 'Yearly']" :value="$v('billing_interval', 'month')" required /></x-ui.field>
                <x-ui.field label="Trial length (days)" name="trial_days" :required="true"><x-ui.input type="number" name="trial_days" :value="$v('trial_days', 14)" min="0" max="365" required /></x-ui.field>
                <x-ui.field label="Sort order" name="sort" :required="true" help="Lower numbers come first."><x-ui.input type="number" name="sort" :value="$v('sort', 10)" min="0" max="32767" required /></x-ui.field>
            </div>
            <div class="pf-checks" style="margin-top: 16px">
                <x-ui.checkbox name="is_active" label="Active" help="Inactive plans cannot be chosen for new subscriptions." :checked="(bool) $v('is_active', true)" />
                <x-ui.checkbox name="is_public" label="Public" help="Shown to organizations that choose their own plan." :checked="(bool) $v('is_public', true)" />
            </div>
        </x-ui.card>

        <x-ui.card title="Modules" description="Tick the modules this plan includes.">
            <div class="pf-checks">
                @foreach ($boolDefs as $def)
                    <x-ui.checkbox :name="'features['.$def['key'].']'" :label="$def['name']" :help="$def['description']"
                        :checked="isset($rows[$def['key']]) ? (bool) $rows[$def['key']]->enabled : false" />
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card title="Limits" description="Leave a number empty or tick Unlimited for no cap.">
            <div class="pf-limits">
                @foreach ($limitDefs as $def)
                    @php
                        $row = $rows[$def['key']] ?? null;
                        $unlimitedNow = $row !== null && $row->limit_value === null;
                    @endphp
                    <div class="pf-limit">
                        <x-ui.field :label="$def['name'].' ('.$def['unit'].')'" :name="'limits['.$def['key'].']'" :id="'limit-'.$def['key']" :help="$def['description']">
                            <x-ui.input type="number" :name="'limits['.$def['key'].']'" :id="'limit-'.$def['key']" min="0" max="2000000000" :value="$row?->limit_value" />
                        </x-ui.field>
                        <x-ui.checkbox :name="'unlimited['.$def['key'].']'" label="Unlimited" :checked="$unlimitedNow" />
                    </div>
                @endforeach
            </div>
        </x-ui.card>

        <div class="form-actions">
            <x-ui.button variant="secondary" :href="route('platform.plans.index')">Cancel</x-ui.button>
            <x-ui.button type="submit">{{ $editing ? 'Save plan' : 'Create plan' }}</x-ui.button>
        </div>
    </form>
</x-layouts.platform>
