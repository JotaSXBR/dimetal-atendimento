@props(['active'])

{{-- As duas auditorias: a de tickets (o que mudou cada atendimento) e a do sistema (acesso, usuários, backup e configurações). --}}
<nav class="flex gap-1 border-b border-line" aria-label="Tipo de auditoria">
    @foreach (['tickets' => ['Tickets', 'auditoria'], 'sistema' => ['Sistema', 'auditoria.sistema']] as $key => [$label, $route])
        <a
            href="{{ route($route) }}"
            wire:navigate
            @if ($active === $key) aria-current="page" @endif
            class="-mb-px border-b-2 px-3 py-2 text-sm {{ $active === $key ? 'border-ink font-medium text-ink' : 'border-transparent text-ink-3 hover:text-ink' }}"
        >{{ $label }}</a>
    @endforeach
</nav>
