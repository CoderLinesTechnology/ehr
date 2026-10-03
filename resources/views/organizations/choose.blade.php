<x-layouts.minimal title="Choose an organization">
    <div class="stack stack--lg">
        <x-ui.page-header title="Choose an organization" description="You're a member of more than one organization. Each one keeps its records completely separate." />

        <ul class="choice-list" role="list">
            @foreach ($organizations as $organization)
                <li>
                    <a class="choice-list__item" href="{{ route('app.dashboard', ['organization' => $organization->slug]) }}">
                        <x-ui.avatar :name="$organization->name" size="md" />
                        <span class="choice-list__text">
                            <span class="choice-list__title">{{ $organization->name }}</span>
                            @unless ($organization->allowsAccess())
                                <x-ui.badge :tone="$organization->status->tone()">{{ $organization->status->label() }}</x-ui.badge>
                            @endunless
                        </span>
                        <x-ui.icon name="chevron-right" />
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.minimal>
