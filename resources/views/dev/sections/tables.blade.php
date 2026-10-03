<section class="sg-block" id="tables">
    <h2>Tables and pagination</h2>
    <p class="sg-lead"><code>&lt;x-ui.table&gt;</code> scrolls sideways on narrow screens and stays keyboard reachable. <code>&lt;x-ui.sort-link&gt;</code> toggles <code>?sort=…&amp;direction=…</code> and keeps every other query parameter.</p>

    <x-ui.card :padded="false">
        <x-ui.table label="Sample clients" :striped="true">
            <caption class="sr-only">Sample clients, sortable by name and status</caption>
            <thead>
                <tr>
                    <x-ui.sort-link :th="true" column="name" label="Name" />
                    <x-ui.sort-link :th="true" column="status" label="Status" />
                    <th scope="col">Next appointment</th>
                    <th scope="col" class="table__actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>
                            <div class="cluster">
                                <x-ui.avatar :name="$row['name']" size="sm" :decorative="true" />
                                <strong>{{ $row['name'] }}</strong>
                                @if ($row['demo'])<x-ui.badge tone="demo">Demo</x-ui.badge>@endif
                            </div>
                        </td>
                        @php($tone = match ($row['status']) { 'Active' => 'success', 'Waitlist' => 'warning', 'Intake' => 'info', default => 'neutral' })
                        <td>
                            <x-ui.badge :tone="$tone">{{ $row['status'] }}</x-ui.badge>
                        </td>
                        <td class="tabular">{{ $row['next'] }}</td>
                        <td class="table__actions">
                            <x-ui.dropdown label="Actions for {{ $row['name'] }}" icon="more-horizontal" align="right" size="sm">
                                <x-ui.dropdown-item href="#tables" icon="eye">View</x-ui.dropdown-item>
                                <x-ui.dropdown-item href="#tables" icon="edit">Edit</x-ui.dropdown-item>
                                <div class="dropdown__sep" role="separator"></div>
                                <x-ui.dropdown-item data-dialog-open="sg-archive" icon="archive" tone="danger">Archive</x-ui.dropdown-item>
                            </x-ui.dropdown>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table>
    </x-ui.card>

    <div class="sg-demo" style="margin-top: 1rem">
        <p class="sg-label">Compact table</p>
        <x-ui.table :compact="true" label="Compact example">
            <thead><tr><th scope="col">Service</th><th scope="col" class="table__num">Duration</th><th scope="col" class="table__num">Price</th></tr></thead>
            <tbody>
                <tr><td>Initial assessment</td><td class="table__num tabular">60 min</td><td class="table__num tabular">GHS 450.00</td></tr>
                <tr><td>Follow-up session</td><td class="table__num tabular">30 min</td><td class="table__num tabular">GHS 250.00</td></tr>
            </tbody>
        </x-ui.table>
    </div>

    <div class="sg-demo" style="margin-top: 1rem">
        <p class="sg-label">Pagination</p>
        {{ $paginator->links('pagination.default') }}
        <hr>
        {{ $simplePaginator->links('pagination.simple-default') }}
    </div>

    <x-ui.modal id="sg-archive" title="Archive this client?" size="sm" description="Archived clients are hidden from lists but their records are kept.">
        <x-slot:footer>
            <x-ui.button variant="secondary" data-dialog-close>Cancel</x-ui.button>
            <x-ui.button variant="danger" data-dialog-close>Archive</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</section>
