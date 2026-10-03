<section class="sg-block" id="overlays">
    <h2>Dialogs</h2>
    <p class="sg-lead">Native <code>&lt;dialog&gt;</code>. Any element with <code>data-dialog-open="id"</code> opens it; <code>data-dialog-close</code> or <kbd>Esc</kbd> closes it; focus returns to whatever opened it. <code>:open="true"</code> re-opens a dialog on page load, for example after a validation error.</p>

    <div class="sg-demo">
        <div class="sg-row">
            <x-ui.button variant="secondary" data-dialog-open="sg-modal">Open modal</x-ui.button>
            <x-ui.button variant="secondary" data-dialog-open="sg-drawer">Open drawer</x-ui.button>
            <x-ui.confirm-form
                action="{{ route('dev.styleguide.flash') }}"
                method="POST"
                title="Cancel this appointment?"
                message="The client will be notified. This cannot be undone."
                confirm-label="Cancel appointment"
                cancel-label="Keep appointment"
                button-label="Cancel appointment"
                reason-field="reason"
                reason-label="Reason for cancelling"
                :reason-required="true">
                <input type="hidden" name="kind" value="warning">
            </x-ui.confirm-form>
            <x-ui.confirm-form
                action="{{ route('dev.styleguide.flash') }}"
                method="POST"
                title="Generate new codes?"
                message="Your old codes stop working."
                confirm-label="Generate"
                tone="primary"
                button-label="Primary confirm"
                button-variant="secondary">
                <input type="hidden" name="kind" value="info">
            </x-ui.confirm-form>
        </div>
    </div>

    <x-ui.modal id="sg-modal" title="Add a note" description="Notes are visible to the care team.">
        <form id="sg-modal-form" method="POST" action="{{ route('dev.styleguide.flash') }}" class="form" data-submit-once>
            @csrf
            <input type="hidden" name="kind" value="success">
            <x-ui.field label="Note" name="sg_modal_note" :required="true">
                <x-ui.textarea name="sg_modal_note" rows="4" required />
            </x-ui.field>
        </form>
        <x-slot:footer>
            <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
            <x-ui.button type="submit" form="sg-modal-form">Save note</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>

    <x-ui.drawer id="sg-drawer" title="Filters" description="Narrow the list down.">
        <div class="form">
            <x-ui.field label="Status" name="sg_drawer_status">
                <x-ui.select name="sg_drawer_status" placeholder="Any status" :options="['active' => 'Active', 'waitlist' => 'Waitlist', 'discharged' => 'Discharged']" />
            </x-ui.field>
            <x-ui.field label="Clinician" name="sg_drawer_clin">
                <x-ui.select name="sg_drawer_clin" placeholder="Anyone" :options="['1' => 'Dr. Mensah', '2' => 'Dr. Okafor']" />
            </x-ui.field>
        </div>
        <x-slot:footer>
            <x-ui.button variant="secondary" data-dialog-close>Reset</x-ui.button>
            <x-ui.button data-dialog-close>Apply filters</x-ui.button>
        </x-slot:footer>
    </x-ui.drawer>
</section>
