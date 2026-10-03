<section class="sg-block" id="design">
    <h2>Page header, stat cards, tiles, list rows</h2>
    <div class="sg-demo">
        <p class="sg-label">Page header: circle tile / rounded-square tile</p>
        <x-ui.page-header title="Clients" description="Manage your clients and view their information." icon="users">
            <x-slot:actions><x-ui.button icon="plus">Add Client</x-ui.button></x-slot:actions>
        </x-ui.page-header>
        <x-ui.page-header title="Appointments" description="Manage appointments and your calendar." icon="calendar" icon-shape="square">
            <x-slot:actions><x-ui.button variant="secondary">Add Availability</x-ui.button><x-ui.button icon="plus">New Appointment</x-ui.button></x-slot:actions>
        </x-ui.page-header>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Stat cards (<code>.stat-row</code>); trend, alert and plain variants</p>
        <div class="stat-row">
            <x-ui.stat label="Total Clients" value="48" icon="users" tone="blue" :trend="12" />
            <x-ui.stat label="Active Clients" value="42" icon="user-check" tone="green" :trend="10" />
            <x-ui.stat label="Cancellations" value="3" icon="calendar-check" tone="red" :trend="-8" />
            <x-ui.stat label="Unread messages" value="7" icon="message-circle" shape="circle" alert="1 new" hint="Since yesterday" />
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Icon tiles</p>
        <div class="sg-row">
            @foreach (['default', 'blue', 'green', 'sky', 'purple', 'amber', 'red', 'pink', 'neutral'] as $tone)<x-ui.icon-tile icon="heart" :tone="$tone" />@endforeach
            <x-ui.icon-tile icon="heart" shape="square" :size="36" /><x-ui.icon-tile icon="calendar" shape="outlined" :size="60" :icon-size="30" />
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">List rows inside a card</p>
        <x-ui.card title="Upcoming Appointments" :padded="false">
            <x-slot:actions><a href="#design" class="filter-panel__reset">View all</a></x-slot:actions>
            <x-ui.list-row title="Emily Johnson" subtitle="Individual Therapy" href="#design" :chevron="true">
                <x-slot:lead><x-ui.avatar name="Emily Johnson" /></x-slot:lead>
                <x-slot:trail><x-ui.badge tone="success">Confirmed</x-ui.badge></x-slot:trail>
            </x-ui.list-row>
            <x-ui.list-row title="Michael Brown" subtitle="Telehealth follow-up">
                <x-slot:lead><x-ui.icon-tile icon="video" tone="purple" /></x-slot:lead>
                <x-slot:trail><span>11:30 AM</span></x-slot:trail>
            </x-ui.list-row>
        </x-ui.card>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Stepper and progress</p>
        <x-ui.stepper :steps="['Personal Info', 'Health Info', 'Confirmation']" :current="2" />
        <x-ui.progress :value="67" label="Profile completion" :percentage="true" />
        <div class="sg-row"><x-ui.spinner /><x-ui.empty-state icon="inbox" title="No clients yet" description="Add your first client to get started." /></div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Segmented control, pill tabs, mini calendar</p>
        <x-ui.segmented :items="[['label' => 'Day', 'key' => 'day'], ['label' => 'Week', 'key' => 'week', 'active' => true], ['label' => 'Month', 'key' => 'month']]" label="Calendar view" />
        <x-ui.tabs variant="pills" :tabs="[['label' => 'All', 'url' => '#a', 'active' => true], ['label' => 'Upcoming', 'url' => '#b'], ['label' => 'Past', 'url' => '#c']]" />
        <div style="max-width: 280px"><x-ui.mini-calendar month="2025-04" selected="2025-04-28" today="2025-04-28" :marked="['2025-04-29', '2025-05-02']" href="#day-{date}" prev-url="#prev" next-url="#next" /></div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Notifications dropdown (with items) and top-bar dot rule</p>
        <x-ui.notifications :unread="2" view-all-url="#all" :items="[
            ['title' => 'New message from Emily', 'subtitle' => 'Can we move to Thursday?', 'time' => '2m', 'icon' => 'message-circle', 'unread' => true],
            ['title' => 'Appointment confirmed', 'subtitle' => 'Michael Brown, Apr 29', 'time' => '1h', 'icon' => 'calendar-check', 'tone' => 'green', 'unread' => true, 'mention' => true],
            ['title' => 'Document signed', 'subtitle' => 'Intake form', 'time' => 'Yesterday', 'icon' => 'file-text'],
        ]" />
    </div>
</section>
