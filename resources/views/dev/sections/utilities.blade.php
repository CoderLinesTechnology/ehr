<section class="sg-block" id="utilities">
    <h2>Layout utilities</h2>
    <p class="sg-lead">Shared classes for page layout, so modules do not invent their own spacing.</p>

    <div class="sg-demo">
        <p class="sg-label"><code>.stack</code>, <code>.stack--sm</code>, <code>.stack--lg</code></p>
        <div class="sg-split">
            @foreach (['stack--sm' => 'small gap', 'stack' => 'default gap', 'stack--lg' => 'large gap'] as $class => $text)
                <div class="{{ $class === 'stack' ? 'stack' : 'stack '.$class }}">
                    <div class="sg-box">One</div><div class="sg-box">Two</div><div class="sg-box">Three ({{ $text }})</div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="sg-demo">
        <p class="sg-label"><code>.cluster</code>, <code>.cluster--between</code></p>
        <div class="cluster"><span class="sg-box">Wraps</span><span class="sg-box">and aligns</span><span class="sg-box">in a row</span></div>
        <div class="cluster cluster--between" style="margin-top: .75rem"><span class="sg-box">Left</span><span class="sg-box">Right</span></div>
    </div>

    <div class="sg-demo">
        <p class="sg-label"><code>.grid-2</code>, <code>.grid-3</code>, <code>.grid-4</code> (collapse by available width, never more columns than named)</p>
        <div class="grid-2"><div class="sg-box">1</div><div class="sg-box">2</div></div>
        <div class="grid-3" style="margin-top: .75rem"><div class="sg-box">1</div><div class="sg-box">2</div><div class="sg-box">3</div></div>
        <div class="grid-4" style="margin-top: .75rem"><div class="sg-box">1</div><div class="sg-box">2</div><div class="sg-box">3</div><div class="sg-box">4</div></div>
    </div>

    <div class="sg-demo">
        <p class="sg-label"><code>.choice-list</code> for picking one of several things</p>
        <ul class="choice-list" role="list">
            <li>
                <a href="#utilities" class="choice-list__item">
                    <x-ui.avatar name="Harbor Light Behavioral Health" :decorative="true" />
                    <span class="choice-list__text"><span class="choice-list__title">Harbor Light Behavioral Health</span><span class="choice-list__meta">Owner &middot; 24 team members</span></span>
                    <x-ui.icon name="chevron-right" :size="18" class="choice-list__chevron" />
                </a>
            </li>
            <li>
                <a href="#utilities" class="choice-list__item">
                    <x-ui.avatar name="Northfield Counseling" :decorative="true" />
                    <span class="choice-list__text"><span class="choice-list__title">Northfield Counseling Group</span><span class="choice-list__meta">Clinician</span></span>
                    <x-ui.icon name="chevron-right" :size="18" class="choice-list__chevron" />
                </a>
            </li>
        </ul>
    </div>

    <div class="sg-demo">
        <p class="sg-label"><code>.status-page</code> and text helpers</p>
        <p class="text-muted">.text-muted</p>
        <p class="text-sm">.text-sm</p>
        <p class="text-xs">.text-xs</p>
        <p class="text-right">.text-right</p>
        <p class="nowrap">.nowrap keeps this on one line however narrow the screen gets.</p>
        <p class="truncate" style="max-width: 16rem">.truncate cuts a very long single line with an ellipsis</p>
        <x-ui.status-page icon="clock" title="Awaiting approval" message="Your organization is being reviewed. We will email you as soon as it is approved." style="margin: 1rem auto 0" />
    </div>
</section>
