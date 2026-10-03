<section class="sg-block" id="data">
    <h2>Data display</h2>
    <p class="sg-lead">Stat tiles, avatars, description lists and settings sections.</p>

    <div class="grid-4">
        <x-ui.stat label="Today's appointments" value="12" hint="3 remaining" icon="calendar" href="#data" />
        <x-ui.stat label="Active clients" value="248" hint="+6 this week" icon="users" />
        <x-ui.stat label="Unsigned notes" value="5" hint="Oldest from Monday" icon="file" href="#data" />
        <x-ui.stat label="Open tasks" value="18" icon="check-square" />
    </div>

    <div class="sg-demo" style="margin-top: 1rem">
        <p class="sg-label">Avatars</p>
        <div class="sg-row">
            <x-ui.avatar name="Avery Mensah" size="sm" />
            <x-ui.avatar name="Kwame Boateng" />
            <x-ui.avatar name="Priya Natarajan" size="lg" />
            <x-ui.avatar name="Sam Okafor" size="xl" />
            <x-ui.avatar name="Lena Fischer" color="#5b8def" />
            <x-ui.avatar name="Mateo Alvarez" color="#f0b429" />
            <x-ui.avatar name="Imani Clarke" color="#0f6b55" size="lg" />
        </div>
    </div>

    <div class="sg-demo" style="margin-top: 1rem">
        <p class="sg-label">Description list</p>
        <x-ui.dl>
            <x-ui.dl-item label="Date of birth">12 April 1988 (38)</x-ui.dl-item>
            <x-ui.dl-item label="Phone">+233 24 000 0000</x-ui.dl-item>
            <x-ui.dl-item label="Email">jordan.avery@example.org</x-ui.dl-item>
            <x-ui.dl-item label="Preferred pharmacy"></x-ui.dl-item>
            <x-ui.dl-item label="Address" :wide="true">14 Example Street, Accra</x-ui.dl-item>
        </x-ui.dl>
    </div>

    <div class="sg-demo" style="margin-top: 1rem">
        <p class="sg-label">Settings section</p>
        <x-ui.section title="Business hours" description="Used when clients book online and when the calendar shows open slots.">
            <x-ui.card>
                <div class="form">
                    <x-ui.field label="Opens" name="sg_opens"><x-ui.input type="time" name="sg_opens" value="08:00" /></x-ui.field>
                    <x-ui.field label="Closes" name="sg_closes"><x-ui.input type="time" name="sg_closes" value="17:00" /></x-ui.field>
                </div>
            </x-ui.card>
        </x-ui.section>
    </div>
</section>
