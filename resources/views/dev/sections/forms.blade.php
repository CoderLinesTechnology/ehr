<section class="sg-block" id="forms">
    <h2>Forms</h2>
    <p class="sg-lead"><code>&lt;x-ui.field&gt;</code> renders the label, help and error for a control and wires <code>for</code>, <code>id</code> and <code>aria-describedby</code>. Controls fill themselves from <code>old()</code> and mark <code>aria-invalid</code> when the field has an error. Pass the same <code>id</code> to the field and the control only if you override it.</p>

    <div class="sg-demo">
        <p class="sg-label">Text controls</p>
        <div class="form form-grid">
            <x-ui.field label="Full name" name="sg_name" :required="true" help="As it appears on their insurance card.">
                <x-ui.input name="sg_name" value="Jordan Avery" autocomplete="off" required />
            </x-ui.field>
            <x-ui.field label="Email address" name="sg_mail" optional>
                <x-ui.input type="email" name="sg_mail" placeholder="name@example.org" autocomplete="off" />
            </x-ui.field>
            <x-ui.field label="Password" name="sg_pw" help="The eye button shows or hides what you typed.">
                <x-ui.input type="password" name="sg_pw" autocomplete="off" />
            </x-ui.field>
            <x-ui.field label="Search icon" name="sg_icon">
                <x-ui.input name="sg_icon" icon="search" placeholder="Input with a leading icon" />
            </x-ui.field>
            <x-ui.field label="Date of birth" name="sg_dob"><x-ui.input type="date" name="sg_dob" value="1988-04-12" /></x-ui.field>
            <x-ui.field label="Time" name="sg_time"><x-ui.input type="time" name="sg_time" value="09:30" /></x-ui.field>
            <x-ui.field label="Starts at" name="sg_dt"><x-ui.input type="datetime-local" name="sg_dt" value="2026-10-12T09:30" /></x-ui.field>
            <x-ui.field label="Disabled" name="sg_disabled"><x-ui.input name="sg_disabled" value="Read only value" disabled /></x-ui.field>
            <div class="form-grid__full">
                <x-ui.field label="Notes" name="sg_notes" help="Plain text only. Not shown to the client.">
                    <x-ui.textarea name="sg_notes" rows="3" value="Prefers morning appointments." />
                </x-ui.field>
            </div>
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Validation errors</p>
        <div class="form form-grid">
            <x-ui.field label="Email address" name="sg_email" :required="true">
                <x-ui.input type="email" name="sg_email" value="not-an-email" required />
            </x-ui.field>
            <x-ui.field label="Password" name="sg_password" :required="true" help="At least 12 characters, with letters and numbers.">
                <x-ui.input type="password" name="sg_password" required />
            </x-ui.field>
            <x-ui.field label="Role" name="sg_role" :required="true">
                <x-ui.select name="sg_role" :options="['clinician' => 'Clinician', 'admin' => 'Administrator']" placeholder="Choose a role" required />
            </x-ui.field>
            <div>
                <x-ui.checkbox name="sg_terms" label="I agree to the Terms of Service and Privacy Policy" :hidden-default="false" required />
            </div>
        </div>
        <p class="text-muted text-sm" style="margin: 1rem 0 0">Errors carry an icon and text, never colour alone, and the first invalid field takes focus on page load.</p>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Select, choices and toggles</p>
        <div class="form form-grid">
            <x-ui.field label="Timezone" name="sg_tz" help="Option groups are supported.">
                <x-ui.select name="sg_tz" value="Africa/Accra" :options="['Africa' => ['Africa/Accra' => '(UTC+00:00) Accra', 'Africa/Lagos' => '(UTC+01:00) Lagos', 'Africa/Nairobi' => '(UTC+03:00) Nairobi'], 'Europe' => ['Europe/London' => '(UTC+01:00) London', 'Europe/Berlin' => '(UTC+02:00) Berlin']]" />
            </x-ui.field>
            <x-ui.field label="Locations" name="sg_locations[]" help="Hold Ctrl or Cmd to pick several.">
                <x-ui.select name="sg_locations[]" :multiple="true" :value="['a', 'c']" :options="['a' => 'Main clinic', 'b' => 'Riverside office', 'c' => 'Telehealth']" />
            </x-ui.field>
            <x-ui.field label="Appointment type" name="sg_type">
                <x-ui.radio-group name="sg_type" value="followup" :options="['intake' => ['label' => 'Intake', 'description' => '60 minutes with a clinician'], 'followup' => ['label' => 'Follow-up', 'description' => '30 minutes'], 'group' => 'Group session']" />
            </x-ui.field>
            <x-ui.field label="Reminders" name="sg_remind">
                <x-ui.radio-group name="sg_remind" value="email" :inline="true" :options="['email' => 'Email', 'sms' => 'SMS', 'none' => 'None']" />
            </x-ui.field>
            <div class="stack stack--sm">
                <x-ui.checkbox name="sg_check_a" label="Send an appointment reminder" :checked="true" help="Sent the day before." />
                <x-ui.checkbox name="sg_check_b" label="Allow double booking" />
                <x-ui.toggle name="sg_toggle_a" label="Online booking" :checked="true" help="Clients can book without calling." />
                <x-ui.toggle name="sg_toggle_b" label="Waitlist" />
            </div>
            <x-ui.field label="Calendar colour" name="sg_color" help="Pick a swatch or type a hex value.">
                <x-ui.color-input name="sg_color" value="#5b8def" />
            </x-ui.field>
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Form layout</p>
        <p class="text-muted text-sm"><code>.form</code> spaces fields, <code>.form-grid</code> becomes two columns when there is room, <code>.form-actions</code> right-aligns the buttons.</p>
        <form class="form" method="POST" action="{{ route('dev.styleguide.flash') }}" data-submit-once>
            @csrf
            <input type="hidden" name="kind" value="success">
            <div class="form-grid">
                <x-ui.field label="First name" name="sg_first"><x-ui.input name="sg_first" /></x-ui.field>
                <x-ui.field label="Last name" name="sg_last"><x-ui.input name="sg_last" /></x-ui.field>
            </div>
            <div class="form-actions form-actions--divided">
                <x-ui.button variant="secondary">Cancel</x-ui.button>
                <x-ui.button type="submit">Save client</x-ui.button>
            </div>
        </form>
    </div>
</section>
