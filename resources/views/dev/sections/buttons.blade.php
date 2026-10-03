<section class="sg-block" id="buttons">
    <h2>Buttons</h2>
    <p class="sg-lead"><code>&lt;x-ui.button&gt;</code> renders a button, or a link when <code>href</code> is set. Variants: primary, secondary, tonal, neutral, success, warning, danger, info, ghost, link. Sizes: xs 24, sm 32 (compact), md 40 (default), lg 44. Extra attributes pass through.</p>

    <div class="sg-demo">
        <p class="sg-label">Variants (default, disabled)</p>
        @foreach (['primary', 'secondary', 'tonal', 'neutral', 'success', 'warning', 'danger', 'info', 'ghost', 'link'] as $variant)
            <div class="sg-row">
                <x-ui.button :variant="$variant">{{ ucfirst($variant) }}</x-ui.button>
                <x-ui.button :variant="$variant" icon="plus">With icon</x-ui.button>
                <x-ui.button :variant="$variant" icon="pencil" aria-label="Edit" />
                <x-ui.button :variant="$variant" disabled>Disabled</x-ui.button>
            </div>
        @endforeach
    </div>

    <div class="sg-demo">
        <p class="sg-label">Sizes</p>
        <div class="sg-row">
            <x-ui.button size="xs" icon="plus">XS 24</x-ui.button>
            <x-ui.button size="sm" icon="plus">Small 32</x-ui.button>
            <x-ui.button size="md" icon="plus">Medium 40</x-ui.button>
            <x-ui.button size="lg" icon="plus">Large 44</x-ui.button>
            <x-ui.button variant="secondary" icon-right="arrow-right">Continue</x-ui.button>
            <x-ui.button href="#buttons" variant="tonal" size="sm" icon="funnel">Filters</x-ui.button>
            <x-ui.button variant="tonal" size="sm" icon="pencil">Edit</x-ui.button>
            <button type="button" class="icon-btn icon-btn--kebab" aria-label="More actions"><x-ui.icon name="ellipsis" :size="16" /></button>
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Submit once</p>
        <p class="text-muted text-sm">A form with <code>data-submit-once</code> locks its buttons after the first submit.</p>
        <form method="POST" action="{{ route('dev.styleguide.flash') }}" data-submit-once class="cluster">
            @csrf
            <input type="hidden" name="kind" value="success">
            <x-ui.button type="submit" icon="check">Save appointment</x-ui.button>
        </form>
    </div>
</section>
