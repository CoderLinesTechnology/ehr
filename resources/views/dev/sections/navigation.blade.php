<section class="sg-block" id="navigation">
    <h2>Navigation</h2>
    <p class="sg-lead">Server-side tabs, breadcrumbs and dropdown menus. The dropdown is a <code>&lt;details&gt;</code>, so it works without JavaScript; the script adds <kbd>Esc</kbd>, arrow keys and placement that never gets clipped.</p>

    <div class="sg-demo">
        <p class="sg-label">Tabs</p>
        <x-ui.tabs label="Client sections" :tabs="[
            ['label' => 'Overview', 'url' => '#navigation', 'active' => true],
            ['label' => 'Appointments', 'url' => '#navigation', 'active' => false, 'count' => 12],
            ['label' => 'Notes', 'url' => '#navigation', 'active' => false, 'count' => 4],
            ['label' => 'Billing', 'url' => '#navigation', 'active' => false],
            ['label' => 'Documents', 'url' => '#navigation', 'active' => false, 'count' => 0],
            ['label' => 'Messages', 'url' => '#navigation', 'active' => false],
        ]" />
    </div>

    <div class="sg-demo">
        <p class="sg-label">Dropdowns</p>
        <div class="sg-row">
            <x-ui.dropdown label="Actions" icon="more-horizontal" align="right">
                <x-ui.dropdown-item href="#navigation" icon="eye">View profile</x-ui.dropdown-item>
                <x-ui.dropdown-item href="#navigation" icon="edit">Edit details</x-ui.dropdown-item>
                <x-ui.dropdown-item action="{{ route('dev.styleguide.flash') }}" method="POST" icon="check">Mark as reviewed</x-ui.dropdown-item>
                <div class="dropdown__sep" role="separator"></div>
                <x-ui.dropdown-item data-dialog-open="sg-archive" icon="trash" tone="danger">Delete&hellip;</x-ui.dropdown-item>
            </x-ui.dropdown>
            <x-ui.dropdown label="New" icon="plus">
                <x-ui.dropdown-item href="#navigation" icon="user-plus">Client</x-ui.dropdown-item>
                <x-ui.dropdown-item href="#navigation" icon="calendar-plus">Appointment</x-ui.dropdown-item>
                <x-ui.dropdown-item href="#navigation" icon="file">Document</x-ui.dropdown-item>
            </x-ui.dropdown>
            <x-ui.dropdown label="Sort by" variant="ghost">
                <x-ui.dropdown-item href="#navigation">Name</x-ui.dropdown-item>
                <x-ui.dropdown-item href="#navigation">Last seen</x-ui.dropdown-item>
            </x-ui.dropdown>
        </div>
    </div>
</section>
