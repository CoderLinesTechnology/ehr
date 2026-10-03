<section class="sg-block" id="errors">
    <h2>Error pages and layouts</h2>
    <p class="sg-lead">The status pages share one calm layout and never show exception detail. They also use a frame that does not depend on the shell composer, so they still render when the database is down.</p>

    <div class="sg-demo">
        <div class="sg-row">
            @foreach ([403, 404, 419, 429, 500, 503] as $code)
                <x-ui.button :href="route('dev.styleguide.error', ['code' => $code])" variant="secondary" size="sm">{{ $code }}</x-ui.button>
            @endforeach
            <x-ui.button :href="route('dev.styleguide.platform')" variant="secondary" size="sm" icon="shield">Platform layout</x-ui.button>
            <x-ui.button :href="route('dev.styleguide.auth')" variant="secondary" size="sm" icon="lock">Auth layout</x-ui.button>
            <x-ui.button :href="route('dev.styleguide.minimal')" variant="secondary" size="sm" icon="file">Minimal layout</x-ui.button>
        </div>
    </div>
</section>
