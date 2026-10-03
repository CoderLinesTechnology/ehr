{{-- The rail's Session Details card (comp 11), shared by the join and call pages. Needs $details, $startsAt, $range; $compact for the call page's narrower rail. --}}
<section class="tj-card tj-details{{ ($compact ?? false) ? ' tj-details--compact' : '' }}">
    <h2 class="tj-card__title"><x-ui.icon name="calendar" :size="22" :stroke="1.9" />Session Details</h2>
    <dl>
        <div><x-ui.icon name="clock" :size="24" :stroke="1.7" /><dt>Date &amp; Time</dt><dd>{{ $startsAt }} <i aria-hidden="true">•</i> {{ $range }}</dd></div>
        <div><x-ui.icon name="user" :size="24" :stroke="1.7" /><dt>Client</dt><dd>{{ $details->clientName }}</dd></div>
        <div><x-ui.icon name="user" :size="24" :stroke="1.7" /><dt>Provider</dt><dd>{{ $details->clinicianName }}</dd></div>
        <div><x-ui.icon name="calendar" :size="24" :stroke="1.7" /><dt>Service</dt><dd>{{ $details->serviceName }}</dd></div>
        <div><x-ui.icon name="map-pin" :size="24" :stroke="1.7" /><dt>Location</dt><dd>Telehealth ({{ $details->vendor }})</dd></div>
    </dl>
</section>
