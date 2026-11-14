{{-- O ✕ das faixas fixas do topo (resources/js/alert-dismiss.js): esconde a faixa por um tempo; ela volta sozinha. Nunca silencia o som. --}}
@props(['label' => 'Dispensar aviso'])

<flux:button type="button" size="xs" variant="ghost" icon="x-mark" aria-label="{{ $label }}" data-dismiss-button {{ $attributes->class('shrink-0') }} />
