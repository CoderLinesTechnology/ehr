@php
    $editing = $resource !== null;
    $value = fn (string $field, $fallback = null) => $editing ? ($resource->{$field} instanceof \BackedEnum ? $resource->{$field}->value : $resource->{$field}) : $fallback;
@endphp
<x-layouts.app :title="$editing ? 'Edit resource' : 'Add resource'">
    @push('styles')
        <link rel="stylesheet" href="{{ asset('css/screens/resources.css') }}?v={{ filemtime(public_path('css/screens/resources.css')) }}">
    @endpush

    <div class="res res--form">
        <x-ui.page-header class="res-header" :title="$editing ? 'Edit resource' : 'Add resource'" description="Guides, forms, documents, videos and FAQs for your team and, later, your clients." icon="book-open" />

        <form method="POST" action="{{ $editing ? route('app.resources.update', ['resource' => $resource]) : route('app.resources.store') }}" enctype="multipart/form-data" class="res-form" data-submit-once novalidate>
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="res-form__grid">
                <x-ui.field label="Type" name="type" :required="true"><x-ui.select name="type" :options="$types" :value="$value('type', 'guide')" required /></x-ui.field>
                <x-ui.field label="Who is it for?" name="audience" :required="true" help="Clients see these in the portal when it arrives."><x-ui.select name="audience" :options="$audiences" :value="$value('audience', 'everyone')" required /></x-ui.field>
                <x-ui.field class="res-form__full" label="Title" name="title" :required="true"><x-ui.input name="title" :value="$value('title')" maxlength="120" required /></x-ui.field>
                <x-ui.field class="res-form__full" label="Short description" name="summary" :required="true" help="Shown on the cards. Up to 300 characters."><x-ui.textarea name="summary" :value="$value('summary')" rows="2" maxlength="300" required /></x-ui.field>
                <x-ui.field class="res-form__full" label="Text" name="body" :optional="true" help="Plain text. Leave a blank line between paragraphs. Guides and FAQs are written here."><x-ui.textarea name="body" :value="$value('body')" rows="10" maxlength="20000" /></x-ui.field>
                <x-ui.field class="res-form__full" label="Video link" name="external_url" :optional="true" help="Videos only. Must start with https://. It opens on that website in a new tab; nothing is embedded."><x-ui.input type="url" name="external_url" :value="$value('external_url')" maxlength="2048" placeholder="https://" /></x-ui.field>
                <x-ui.field label="Reading time (minutes)" name="reading_minutes" :optional="true" help="Leave empty to work it out from the text."><x-ui.input type="number" name="reading_minutes" :value="$value('reading_minutes')" min="1" max="600" /></x-ui.field>
                <x-ui.field label="PDF file" name="file" :optional="true" :help="'PDF only, up to '.$maxMegabytes.' MB. It is checked by its content and kept private to your organization.'">
                    <x-ui.input type="file" name="file" accept="application/pdf,.pdf" />
                    @if ($editing && $resource->hasFile())
                        <x-ui.checkbox name="remove_file" label="Remove the current PDF" :checked="false" />
                    @endif
                </x-ui.field>
                <div class="res-form__full">
                    <x-ui.checkbox name="is_featured" label="Feature on the Resources page" :checked="$editing && $resource->is_featured" help="Up to 4 published resources can be featured." />
                </div>
            </div>

            <div class="form-actions">
                <x-ui.button variant="secondary" :href="$editing ? route('app.resources.show', ['resource' => $resource]) : route('app.resources.index')">Cancel</x-ui.button>
                <x-ui.button type="submit">{{ $editing ? 'Save changes' : 'Save draft' }}</x-ui.button>
            </div>
        </form>
    </div>
</x-layouts.app>
