@props(['contact', 'testid', 'max' => 3])

{{-- Chips das etiquetas do contato (cheios), até `max` e "+N" com a lista completa; o contato já vem com `tags` carregado. --}}
@php
    $all = \App\Models\Tag::sortedByName($contact->tags);
    $shown = $all->take($max);
    $rest = $all->slice($max);
@endphp
@if ($all->isNotEmpty())
    <span {{ $attributes->class('flex min-w-0 items-center gap-1 overflow-hidden') }}>
        @foreach ($shown as $tag)
            <x-tag-chip :tag="$tag" :data-testid="$testid" :title="$tag->name" />
        @endforeach
        @if ($rest->isNotEmpty())
            <span class="shrink-0 text-[11px] text-ink-3"><span aria-hidden="true" title="{{ $rest->pluck('name')->join(', ') }}">+{{ $rest->count() }}</span><span class="sr-only">mais etiquetas: {{ $rest->pluck('name')->join(', ') }}</span></span>
        @endif
    </span>
@endif
