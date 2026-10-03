<section class="sg-block" id="tokens">
    <h2>Tokens</h2>
    <p class="sg-lead">Colours, spacing, radii and shadows are CSS custom properties on <code>:root</code>. Copied from <code>docs/design/tokens.css</code> (measured from the comps); the shell values from <code>docs/design/spec/app-shell.md</code> are derived tokens beneath them.</p>

    <div class="sg-demo">
        <p class="sg-label">Surfaces and text</p>
        <div class="sg-swatches">
            @foreach (['--bg-page', '--bg-card', '--bg-sidebar', '--bg-subtle', '--bg-hover', '--bg-active-nav', '--border-default', '--border-input', '--color-text-heading', '--color-text-body', '--color-text-secondary', '--color-text-muted', '--color-text-caption'] as $token)
                <div class="sg-swatch"><span class="sg-swatch__chip" style="--sw: var({{ $token }})"></span><code>{{ $token }}</code></div>
            @endforeach
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Brand and status</p>
        <div class="sg-swatches">
            @foreach (['--color-primary', '--color-primary-hover', '--color-primary-pressed', '--color-success', '--color-warning', '--color-danger', '--color-info', '--color-purple', '--pill-success-bg', '--pill-pending-bg', '--pill-inactive-bg', '--pill-danger-bg', '--pill-warning-bg', '--demo-bg'] as $token)
                <div class="sg-swatch"><span class="sg-swatch__chip" style="--sw: var({{ $token }})"></span><code>{{ $token }}</code></div>
            @endforeach
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Sidebars</p>
        <div class="sg-swatches">
            @foreach (['--shell-sidebar-bg', '--bg-sidebar-dark', '--bg-sidebar-dark-active', '--shell-active-bg', '--shell-foot-bg', '--shell-badge'] as $token)
                <div class="sg-swatch"><span class="sg-swatch__chip" style="--sw: var({{ $token }})"></span><code>{{ $token }}</code></div>
            @endforeach
        </div>
    </div>
</section>
