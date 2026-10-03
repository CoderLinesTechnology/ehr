<x-layouts.app title="Your account">
    <x-slot:header>
        @include('account._header', ['active' => 'profile'])
    </x-slot:header>

    <div class="settings-stack">
        <x-ui.section title="Personal information" description="Your name appears on notes, messages and the audit trail. If you change your email address you will be asked to verify the new one.">
            <form method="POST" action="{{ route('user-profile-information.update') }}" data-submit-once>
                @csrf
                @method('PUT')
                <x-ui.card>
                    <div class="form">
                        <x-ui.field label="Full name" name="name" :required="true" bag="updateProfileInformation">
                            <x-ui.input name="name" :value="$user->name" bag="updateProfileInformation" autocomplete="name" required />
                        </x-ui.field>

                        <x-ui.field label="Email address" name="email" :required="true" bag="updateProfileInformation">
                            <x-ui.input type="email" name="email" :value="$user->email" bag="updateProfileInformation" autocomplete="email" autocapitalize="none" spellcheck="false" required />
                        </x-ui.field>
                    </div>
                    <x-slot:footer>
                        <x-ui.button type="submit">Save changes</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </form>
        </x-ui.section>

        <x-ui.section title="Preferences" description="Choose how dates and times are shown to you.">
            <form method="POST" action="{{ route('account.preferences.update') }}" data-submit-once>
                @csrf
                @method('PUT')
                <x-ui.card>
                    <div class="form">
                        <x-ui.field label="Timezone" name="timezone" help="Pick the timezone you work in.">
                            <x-ui.select name="timezone" :options="$timezoneOptions" :value="$user->timezone" placeholder="Choose a timezone" required />
                        </x-ui.field>
                    </div>
                    <x-slot:footer>
                        <x-ui.button type="submit">Save preferences</x-ui.button>
                    </x-slot:footer>
                </x-ui.card>
            </form>
        </x-ui.section>
    </div>
</x-layouts.app>
