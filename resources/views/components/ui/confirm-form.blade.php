@props([
    'action',
    'method' => 'POST',
    'title' => 'Are you sure?',
    'message' => null,
    'confirmLabel' => 'Confirm',
    'cancelLabel' => 'Cancel',
    'tone' => 'danger',
    'buttonLabel' => 'Delete',
    'buttonVariant' => null,
    'buttonSize' => 'md',
    'buttonIcon' => null,
    'reasonField' => null,
    'reasonLabel' => 'Reason',
    'reasonRequired' => false,
    'bag' => 'default',
])
@php
    $method = strtoupper((string) $method);
    $tone = $tone === 'primary' ? 'primary' : 'danger';
    // Stable per action, so a re-render after a validation error finds the same dialog again.
    $dialogId = 'confirm-'.substr(md5($method.'|'.$action.'|'.$title), 0, 10);
    $formId = $dialogId.'-form';
    $variant = $buttonVariant ?? ($tone === 'danger' ? 'danger' : 'secondary');
    $hasReason = filled($reasonField);
    $reasonHasError = $hasReason && isset($errors) && $errors->getBag($bag)->has($reasonField);
@endphp
{{-- Do not place this inside another <form>. Put it beside the control, not inside a closed <details> (a dialog in a collapsed menu cannot be shown). --}}
<x-ui.button :variant="$variant" :size="$buttonSize" :icon="$buttonIcon" :data-dialog-open="$dialogId" aria-haspopup="dialog">{{ $buttonLabel }}</x-ui.button>
<x-ui.modal :id="$dialogId" :title="$title" :description="$message" size="sm" :open="$reasonHasError" :role="$tone === 'danger' ? 'alertdialog' : null">
    <form method="POST" action="{{ $action }}" id="{{ $formId }}" class="stack" data-submit-once>
        @csrf
        @if (! in_array($method, ['GET', 'POST'], true))@method($method)@endif
        @if ($hasReason)
            <x-ui.field :label="$reasonLabel" :name="$reasonField" :required="$reasonRequired" :bag="$bag" :id="$formId.'-reason'">
                <x-ui.textarea :name="$reasonField" :id="$formId.'-reason'" :bag="$bag" rows="3" :required="$reasonRequired" />
            </x-ui.field>
        @endif
        {{ $slot }}
    </form>
    <x-slot:footer>
        <x-ui.button variant="secondary" data-dialog-close autofocus>{{ $cancelLabel }}</x-ui.button>
        <x-ui.button :variant="$tone === 'danger' ? 'danger' : 'primary'" type="submit" :form="$formId">{{ $confirmLabel }}</x-ui.button>
    </x-slot:footer>
</x-ui.modal>
