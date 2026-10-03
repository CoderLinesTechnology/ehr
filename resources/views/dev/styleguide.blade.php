<x-layouts.app title="Styleguide" :preview-shell="$shell">
    @push('styles')
        {{-- Styleguide-only presentation; the design system itself lives in public/css/app.css. --}}
        <style>
            .sg-toc { display: flex; flex-wrap: wrap; gap: .375rem .5rem; margin: 0 0 2rem; padding: 0; list-style: none; }
            .sg-toc a { display: inline-block; padding: .25rem .625rem; border: 1px solid var(--border); border-radius: 999px; background: var(--surface); color: var(--text-muted); font-size: .8125rem; text-decoration: none; }
            .sg-toc a:hover { color: var(--primary-text); border-color: var(--primary); }
            .sg-block { margin: 0 0 3.5rem; scroll-margin-top: 5rem; }
            .sg-block > h2 { margin: 0 0 .25rem; font-size: 1.25rem; }
            .sg-block > .sg-lead { margin: 0 0 1.25rem; color: var(--text-muted); max-width: 70ch; }
            .sg-demo { padding: 1.5rem; border: 1px solid var(--border); border-radius: var(--radius-lg); background: var(--surface); }
            .sg-demo + .sg-demo { margin-top: 1rem; }
            .sg-label { margin: 0 0 .75rem; color: var(--text-subtle); font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; }
            .sg-swatches { display: grid; grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr)); gap: .75rem; }
            .sg-swatch { display: grid; gap: .375rem; font-size: .75rem; color: var(--text-muted); }
            .sg-swatch__chip { height: 3rem; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--sw); }
            .sg-swatch code { color: var(--text); }
            .sg-icons { display: grid; grid-template-columns: repeat(auto-fill, minmax(7.5rem, 1fr)); gap: .5rem; }
            .sg-icon { display: grid; justify-items: center; gap: .375rem; padding: .875rem .25rem; border: 1px solid var(--border); border-radius: var(--radius); font-size: .6875rem; color: var(--text-muted); text-align: center; word-break: break-word; }
            .sg-icon .icon { color: var(--text); }
            .sg-box { padding: .75rem 1rem; border: 1px dashed var(--border-strong); border-radius: var(--radius-sm); background: var(--surface-2); font-size: .8125rem; color: var(--text-muted); }
            .sg-row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }
            .sg-split { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 22rem), 1fr)); gap: 1.5rem; }
        </style>
    @endpush

    <x-slot:breadcrumbs>
        <x-ui.breadcrumbs :items="[['label' => 'Developer', 'url' => '#'], ['label' => 'Styleguide']]" />
    </x-slot:breadcrumbs>

    <x-slot:header>
        <x-ui.page-header title="Design system" description="Every component, layout and utility with sample data. This page is local only and never touches the database.">
            <x-slot:meta>
                <x-ui.badge tone="demo">Sample data</x-ui.badge>
                <x-ui.badge tone="primary">Light and dark</x-ui.badge>
            </x-slot:meta>
            <x-slot:actions>
                <x-ui.button :href="route('dev.styleguide.platform')" variant="secondary" icon="shield">Platform layout</x-ui.button>
                <x-ui.button :href="route('dev.styleguide.auth')" variant="secondary" icon="lock">Auth layout</x-ui.button>
                <x-ui.button :href="route('dev.styleguide.minimal')" variant="secondary" icon="file">Minimal layout</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>

        <ul class="sg-toc" role="list" aria-label="Jump to a section">
            @foreach (['tokens' => 'Tokens', 'typography' => 'Typography', 'buttons' => 'Buttons', 'forms' => 'Forms', 'cards' => 'Cards', 'tables' => 'Tables', 'badges' => 'Badges', 'feedback' => 'Feedback', 'overlays' => 'Dialogs', 'navigation' => 'Navigation', 'data' => 'Data display', 'filters' => 'Search and filters', 'utilities' => 'Utilities', 'errors' => 'Error pages', 'icons' => 'Icons'] as $anchor => $label)
                <li><a href="#{{ $anchor }}">{{ $label }}</a></li>
            @endforeach
        </ul>
    </x-slot:header>

    @include('dev.sections.tokens')
    @include('dev.sections.typography')
    @include('dev.sections.buttons')
    @include('dev.sections.forms')
    @include('dev.sections.cards')
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
