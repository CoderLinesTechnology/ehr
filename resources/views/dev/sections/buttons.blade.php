<section class="sg-block" id="buttons">
    <h2>Buttons</h2>
    <p class="sg-lead"><code>&lt;x-ui.button&gt;</code> renders a button, or a link when <code>href</code> is set. Extra attributes pass straight through.</p>

    <div class="sg-demo">
        <p class="sg-label">Variants</p>
        <div class="sg-row">
            <x-ui.button>Primary</x-ui.button>
            <x-ui.button variant="secondary">Secondary</x-ui.button>
            <x-ui.button variant="ghost">Ghost</x-ui.button>
            <x-ui.button variant="danger">Danger</x-ui.button>
            <x-ui.button variant="link">Link style</x-ui.button>
            <x-ui.button disabled>Disabled</x-ui.button>
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Sizes and icons</p>
        <div class="sg-row">
            <x-ui.button size="sm" icon="plus">Small</x-ui.button>
            <x-ui.button size="md" icon="plus">Medium</x-ui.button>
            <x-ui.button size="lg" icon="plus">Large</x-ui.button>
            <x-ui.button variant="secondary" icon="download">Export</x-ui.button>
            <x-ui.button variant="secondary" icon-right="arrow-right">Continue</x-ui.button>
            <x-ui.button variant="secondary" icon="edit" aria-label="Edit record" />
            <x-ui.button href="#buttons" variant="secondary" icon="external-link">As a link</x-ui.button>
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Submit once</p>
        <p class="text-muted text-sm">Any form with <code>data-submit-once</code> locks its buttons after the first submit and shows a spinner, so a double click cannot book twice.</p>
        <form method="POST" action="{{ route('dev.styleguide.flash') }}" data-submit-once class="cluster">
            @csrf
            <input type="hidden" name="kind" value="success">
            <x-ui.button type="submit" icon="check">Save appointment</x-ui.button>
        </form>
    </div>
</section>
