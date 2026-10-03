@props(['name', 'size' => 20, 'label' => null, 'stroke' => 1.75])
{{-- Real Lucide SVG (resources/icons/lucide), inlined by App\Support\Icons. Decorative unless a label is given. Unknown names render nothing. --}}
{!! \App\Support\Icons::svg((string) $name, $size, $label, $stroke, (string) $attributes->get('class', '')) !!}
