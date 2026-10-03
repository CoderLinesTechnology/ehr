<x-layouts.platform title="Organizations" :preview-shell="$shell">
    <x-slot:breadcrumbs>
        <x-ui.breadcrumbs :items="[['label' => 'Platform', 'url' => '#'], ['label' => 'Organizations']]" />
    </x-slot:breadcrumbs>
    <x-slot:header>
        <x-ui.page-header title="Organizations" description="Every customer organization on the platform. Platform screens never show clinical content.">
            <x-slot:actions><x-ui.button icon="plus">New organization</x-ui.button></x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div class="grid-4">
        <x-ui.stat label="Organizations" value="42" icon="building" />
        <x-ui.stat label="Active" value="37" icon="check-circle" />
        <x-ui.stat label="Suspended" value="2" icon="alert-triangle" />
        <x-ui.stat label="Trials ending soon" value="3" icon="clock" />
    </div>

    <x-ui.card title="Recently created" :padded="false" style="margin-top: 1.5rem">
        <x-ui.table label="Recent organizations">
            <thead><tr><th scope="col">Organization</th><th scope="col">Plan</th><th scope="col">Status</th></tr></thead>
            <tbody>
                <tr><td>Harbor Light Behavioral Health</td><td>Growth</td><td><x-ui.badge tone="success">Active</x-ui.badge></td></tr>
                <tr><td>Northfield Counseling Group</td><td>Starter</td><td><x-ui.badge tone="warning">Trial</x-ui.badge></td></tr>
                <tr><td>Lakeside Recovery</td><td>Growth</td><td><x-ui.badge tone="danger">Suspended</x-ui.badge></td></tr>
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <p class="text-muted text-sm" style="margin-top: 1.5rem"><a href="{{ route('dev.styleguide.index') }}">Back to the styleguide</a></p>
</x-layouts.platform>
