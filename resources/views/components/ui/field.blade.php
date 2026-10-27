@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
])

@php
    $hasError = $errors->has($name);
@endphp

<div>
    <label for="{{ $name }}" class="mb-1.5 block text-[13px] font-medium text-ink-2">{{ $label }}</label>

    <input
        id="{{ $name }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ $value ?? old($name) }}"
        @if ($hasError) aria-invalid="true" aria-describedby="{{ $name }}-error" @endif
        {{ $attributes->merge([
            'class' => 'h-9 w-full rounded-[10px] border bg-surface px-3 text-sm text-ink placeholder:text-ink-3 focus:ring-2 focus:ring-accent focus:outline-none '
                .($hasError ? 'border-danger' : 'border-line'),
        ]) }}
    >

    @error($name)
        <p id="{{ $name }}-error" class="mt-1.5 text-xs text-danger">{{ $message }}</p>
    @enderror
</div>
