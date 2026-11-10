@props([
    'sidebar' => false,
])

@php
    $company = \App\Settings\CompanySettings::name();
    $logo = \App\Settings\CompanySettings::logoUrl();
@endphp

{{-- O nome da empresa já aparece em texto ao lado; a marca é decorativa (logo cadastrado ou monograma com a inicial). --}}
@if($sidebar)
    <flux:sidebar.brand :name="$company" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-avatar :src="$logo" :name="$company" shape="rounded" size="sm" single accent decorative />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :name="$company" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center">
            <x-avatar :src="$logo" :name="$company" shape="rounded" size="sm" single accent decorative />
        </x-slot>
    </flux:brand>
@endif
