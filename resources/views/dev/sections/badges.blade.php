<section class="sg-block" id="badges">
    <h2>Badges and avatars</h2>
    <p class="sg-lead">Status is always written out; colour only reinforces it. Screen colours: Active/Confirmed green, Pending blue, Scheduled/Inactive grey. The <strong>demo</strong> tone is striped amber with a flask.</p>

    <div class="sg-demo">
        <p class="sg-label">Status pills</p>
        <div class="sg-row">
            <x-ui.badge tone="success">Active</x-ui.badge>
            <x-ui.badge tone="success">Confirmed</x-ui.badge>
            <x-ui.badge tone="info">Pending</x-ui.badge>
            <x-ui.badge tone="neutral">Scheduled</x-ui.badge>
            <x-ui.badge tone="neutral">Inactive</x-ui.badge>
            <x-ui.badge tone="completed">Completed</x-ui.badge>
            <x-ui.badge tone="warning">Pending (sheet)</x-ui.badge>
            <x-ui.badge tone="danger">No show</x-ui.badge>
            <x-ui.badge tone="purple">Telehealth</x-ui.badge>
            <x-ui.badge tone="teal">Teal</x-ui.badge>
            <x-ui.badge tone="pink">Pink</x-ui.badge>
            <x-ui.badge tone="demo">Demo</x-ui.badge>
            <x-ui.badge tone="priority-high">High Priority</x-ui.badge>
            <x-ui.badge tone="priority-medium">Medium</x-ui.badge>
            <x-ui.badge tone="outline">Low</x-ui.badge>
            <x-ui.badge tone="tag">Clinician</x-ui.badge>
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Avatars: initials (stable tint by name), solid, photo URL, sizes</p>
        <div class="sg-row">
            <x-ui.avatar name="James Wilson" /><x-ui.avatar name="Daniel Thomas" /><x-ui.avatar name="Matthew Scott" /><x-ui.avatar name="Lisa Morgan" />
            <x-ui.avatar name="Sarah Carter" tone="brand" size="shell" />
            <x-ui.avatar name="Photo Person" :src="asset('images/wellnest-mark.svg')" />
            <x-ui.avatar name="Custom Colour" color="#0f766e" />
            <x-ui.avatar name="A B" size="xs" /><x-ui.avatar name="A B" size="sm" /><x-ui.avatar name="A B" size="lg" /><x-ui.avatar name="A B" size="xl" />
            <span class="avatar-stack"><x-ui.avatar name="Emily Johnson" size="sm" /><x-ui.avatar name="Michael Brown" size="sm" /><x-ui.avatar name="Sophia Davis" size="sm" /></span>
        </div>
    </div>
</section>
