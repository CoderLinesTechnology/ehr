<section class="sg-block" id="icons">
    <h2>Icons</h2>
    <p class="sg-lead"><code>&lt;x-ui.icon name="…" :size="18" /&gt;</code> draws inline SVG that inherits the text colour. Decorative by default; pass <code>label</code> to make one meaningful. An unknown name renders nothing and logs a warning locally.</p>

    <div class="sg-icons">
        @foreach ($icons as $icon)
            <div class="sg-icon"><x-ui.icon :name="$icon" :size="22" /><span>{{ $icon }}</span></div>
        @endforeach
    </div>
</section>
