<section class="sg-block" id="cards">
    <h2>Cards</h2>
    <p class="sg-lead"><code>&lt;x-ui.card&gt;</code> takes a title, description, an <code>actions</code> slot for the header and a <code>footer</code> slot.</p>

    <div class="sg-split">
        <x-ui.card title="Upcoming appointments" description="Next seven days">
            <x-slot:actions><x-ui.button size="sm" variant="secondary" icon="calendar-plus">New</x-ui.button></x-slot:actions>
            <p style="margin: 0">Card body content sits on a comfortable 24px padding.</p>
            <x-slot:footer>
                <x-ui.button variant="ghost" size="sm">Dismiss</x-ui.button>
                <x-ui.button size="sm">Open calendar</x-ui.button>
            </x-slot:footer>
        </x-ui.card>

        <x-ui.card title="No padding" description="For tables and lists that run edge to edge" :padded="false">
            <ul class="checklist" role="list" style="margin: 0; padding: 1rem">
                <x-ui.checklist-item :done="true" title="Add your first location" />
                <x-ui.checklist-item title="Invite your team" href="#cards" />
            </ul>
        </x-ui.card>
    </div>

    <div class="sg-demo" style="margin-top: 1rem">
        <p class="sg-label">Page header and breadcrumbs</p>
        <x-ui.page-header title="Jordan Avery" description="Client #C-1042 &middot; Intake completed">
            <x-slot:breadcrumbs>
                <x-ui.breadcrumbs :items="[['label' => 'Clients', 'url' => '#cards'], ['label' => 'Active', 'url' => '#cards'], ['label' => 'Jordan Avery']]" />
            </x-slot:breadcrumbs>
            <x-slot:meta>
                <x-ui.badge tone="success">Active</x-ui.badge>
                <x-ui.badge tone="demo">Demo</x-ui.badge>
            </x-slot:meta>
            <x-slot:actions>
                <x-ui.button variant="secondary" icon="edit">Edit</x-ui.button>
                <x-ui.button icon="calendar-plus">Schedule</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </div>
</section>
