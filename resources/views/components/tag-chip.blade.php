@props(['tag', 'outline' => false])

{{-- Chip de etiqueta pelo tom da paleta (`.tag-<tom>` em app.css, claro e escuro). Cheio = etiqueta do contato; contorno = da conversa. --}}
<span {{ $attributes->class([
    'tag-'.$tag->color->value,
    'inline-flex max-w-24 shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-medium leading-tight',
    'border border-[var(--tag-line)] text-ink-2' => $outline,
    'bg-[var(--tag-bg)] text-[var(--tag-fg)]' => ! $outline,
]) }}><span class="truncate">{{ $tag->name }}</span></span>
