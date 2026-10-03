<section class="sg-block" id="logo">
    <h2>Logo</h2>
    <p class="sg-lead"><code>&lt;x-ui.logo&gt;</code> draws the WellNest two-leaf mark (<code>public/images/wellnest-mark.svg</code>, vectorised from the comps) next to the wordmark.</p>
    <div class="sg-demo">
        <div class="sg-row" style="gap: 32px">
            <x-ui.logo />
            <x-ui.logo size="sm" />
            <x-ui.logo :mark="true" :size="64" />
            <span style="background: var(--bg-sidebar-dark); padding: 14px 18px; border-radius: 12px"><x-ui.logo :light="true" /></span>
        </div>
    </div>
</section>
