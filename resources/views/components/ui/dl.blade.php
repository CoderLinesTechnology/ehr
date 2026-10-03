{{-- The outer box is the query container, so the two columns follow the available width and not the viewport. --}}
<div class="dl-box">
    <dl {{ $attributes->class(['dl']) }}>{{ $slot }}</dl>
</div>
