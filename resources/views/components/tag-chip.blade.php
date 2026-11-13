@props(['tag', 'conversation' => false])

{{-- Chip de etiqueta pelo tom da paleta (`.tag-<tom>` em app.css, claro e escuro). Do contato: quadrado e cheio. Da conversa (`conversation`): redondo e translúcido. --}}
<span {{ $attributes->class([
    'tag-'.$tag->color->value,
    'inline-flex max-w-24 shrink-0 items-center px-2 py-0.5 text-[11px] font-medium leading-tight text-[var(--tag-fg)]',
    'rounded-full border border-[var(--tag-line)]/50 bg-[var(--tag-line)]/15' => $conversation,
    'rounded bg-[var(--tag-bg)]' => ! $conversation,
]) }}><span class="truncate">{{ $tag->name }}</span></span>
