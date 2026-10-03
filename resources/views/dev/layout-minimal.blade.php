<x-layouts.minimal title="Awaiting approval" :preview-shell="$shell">
    <x-ui.status-page icon="clock" title="Your organization is awaiting approval" message="We are reviewing the details you submitted. This usually takes one working day, and we will email you as soon as it is done.">
        <x-ui.button :href="route('dev.styleguide.index')" variant="secondary">Back to the styleguide</x-ui.button>
    </x-ui.status-page>
</x-layouts.minimal>
