<x-layouts.app title="Styleguide" :preview-shell="$shell">

    <x-slot:breadcrumbs>
        <x-ui.breadcrumbs :items="[['label' => 'Developer', 'url' => '#'], ['label' => 'Styleguide']]" />
    </x-slot:breadcrumbs>

    <x-slot:header>
        <x-ui.page-header title="Design system" description="Every component, layout and utility with sample data. This page is local only and never touches the database.">
            <x-slot:meta>
                <x-ui.badge tone="demo">Sample data</x-ui.badge>
                <x-ui.badge tone="primary">Light theme</x-ui.badge>
            </x-slot:meta>
            <x-slot:actions>
                <x-ui.button :href="route('dev.styleguide.platform')" variant="secondary" icon="shield-check">Platform layout</x-ui.button>
                <x-ui.button :href="route('dev.styleguide.auth')" variant="secondary" icon="lock">Auth layout</x-ui.button>
                <x-ui.button :href="route('dev.styleguide.minimal')" variant="secondary" icon="file">Minimal layout</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <ul class="sg-toc" role="list" aria-label="Jump to a section">
            @foreach (['tokens' => 'Tokens', 'typography' => 'Typography', 'logo' => 'Logo', 'buttons' => 'Buttons', 'forms' => 'Forms', 'cards' => 'Cards', 'design' => 'Page header, stats, tiles', 'tables' => 'Tables', 'badges' => 'Badges', 'feedback' => 'Feedback', 'overlays' => 'Dialogs', 'navigation' => 'Navigation', 'data' => 'Data display', 'filters' => 'Search and filters', 'utilities' => 'Utilities', 'errors' => 'Error pages', 'icons' => 'Icons'] as $anchor => $label)
                <li><a href="#{{ $anchor }}">{{ $label }}</a></li>
            @endforeach
        </ul>
    </x-slot:header>

    @include('dev.sections.tokens')
    @include('dev.sections.typography')
    @include('dev.sections.logo')
    @include('dev.sections.buttons')
    @include('dev.sections.forms')
    @include('dev.sections.cards')
    @include('dev.sections.design')
    @include('dev.sections.tables')
    @include('dev.sections.badges')
    @include('dev.sections.feedback')
    @include('dev.sections.overlays')
    @include('dev.sections.navigation')
    @include('dev.sections.data')
    @include('dev.sections.filters')
    @include('dev.sections.utilities')
    @include('dev.sections.errors')
    @include('dev.sections.icons')
</x-layouts.app>
