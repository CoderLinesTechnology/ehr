<section class="sg-block" id="filters">
    <h2>Search and filters</h2>
    <p class="sg-lead"><code>&lt;x-ui.search-input&gt;</code> is a GET form that keeps the other query parameters. In a filter bar, selects with <code>data-autosubmit</code> apply on change; without JavaScript an Apply button appears.</p>

    <div class="sg-demo">
        <div class="cluster">
            <x-ui.search-input name="q" placeholder="Search clients" :keep="['status', 'clinician']" />
            <x-ui.filter-bar :action="url('/dev/styleguide')" label="Client filters">
                <input type="hidden" name="q" value="{{ request('q') }}">
                <x-ui.select name="status" placeholder="Any status" data-autosubmit :value="request('status')" :options="['active' => 'Active', 'waitlist' => 'Waitlist', 'discharged' => 'Discharged']" aria-label="Filter by status" />
                <x-ui.select name="clinician" placeholder="Any clinician" data-autosubmit :value="request('clinician')" :options="['1' => 'Dr. Mensah', '2' => 'Dr. Okafor']" aria-label="Filter by clinician" />
            </x-ui.filter-bar>
        </div>
        <p class="text-muted text-sm" style="margin: 1rem 0 0">The search box is its own form and carries the chosen filters along as hidden inputs (<code>:keep</code>); the filter bar renders its own form and repeats the search term.</p>
    </div>
</section>
