<section class="sg-block" id="tokens">
    <h2>Tokens</h2>
    <p class="sg-lead">Colours, spacing, radii and shadows are CSS custom properties on <code>:root</code>. Dark mode redefines them under <code>prefers-color-scheme</code> and under <code>data-theme="dark"</code>; the toggle in the top bar switches between them.</p>

    <div class="sg-demo">
        <p class="sg-label">Surfaces and text</p>
        <div class="sg-swatches">
            @foreach (['--bg', '--surface', '--surface-2', '--surface-3', '--border', '--border-strong', '--input-border', '--text', '--text-muted', '--text-subtle'] as $token)
                <div class="sg-swatch"><span class="sg-swatch__chip" style="--sw: var({{ $token }})"></span><code>{{ $token }}</code></div>
            @endforeach
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Brand and status</p>
        <div class="sg-swatches">
            @foreach (['--primary', '--primary-hover', '--primary-soft', '--success-solid', '--success-bg', '--warning-solid', '--warning-bg', '--danger-solid', '--danger-bg', '--info-solid', '--info-bg', '--demo-bg', '--demo-border'] as $token)
                <div class="sg-swatch"><span class="sg-swatch__chip" style="--sw: var({{ $token }})"></span><code>{{ $token }}</code></div>
            @endforeach
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label">Sidebars</p>
        <div class="sg-swatches">
            @foreach (['--sidebar-bg', '--sidebar-hover', '--sidebar-text', '--sidebar-accent', '--platform-bg', '--platform-accent'] as $token)
                <div class="sg-swatch"><span class="sg-swatch__chip" style="--sw: var({{ $token }})"></span><code>{{ $token }}</code></div>
            @endforeach
        </div>
    </div>
</section>
