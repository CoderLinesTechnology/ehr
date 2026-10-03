<x-layouts.auth title="Invitation unavailable">
    <x-ui.status-page icon="mail-x" title="This invitation link is no longer valid" message="It may have expired, been used already or been withdrawn. Ask your administrator to send you a new invitation.">
        <x-ui.button variant="secondary" :href="url('/')">Go to the home page</x-ui.button>
    </x-ui.status-page>
</x-layouts.auth>
