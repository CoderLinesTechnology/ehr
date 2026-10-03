@php($featureName = collect(\App\Domain\Saas\FeatureRegistry::definitions())->firstWhere('key', $feature)['name'] ?? 'This module')
<x-layouts.app :title="$featureName">
    <x-ui.empty-state icon="layers" :title="$featureName.' is not included in your plan'"
        description="Your organization's current plan doesn't include this module. An organization administrator can review the plan under Settings → Subscription & usage.">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('app.dashboard')" icon="home">Back to dashboard</x-ui.button>
        </x-slot:actions>
    </x-ui.empty-state>
</x-layouts.app>
