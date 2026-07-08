@props([
    'scrollY' => false,
    'maxHeight' => null,
    'class' => '',
])

<div @class([
    'crm-table-container scrollbar-thin',
    'crm-table-container--scroll-y' => $scrollY,
    $class,
]) @if($maxHeight) style="max-height: {{ $maxHeight }}" @endif>
    {{ $slot }}
</div>
