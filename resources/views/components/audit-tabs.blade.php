@props(['active'])

{{-- As duas auditorias: a de tickets (o que mudou cada atendimento) e a do sistema (acesso, usuários, backup e configurações). --}}
{{-- Vai no slot `tabs` do x-page-header: a linha de baixo é a do cabeçalho. --}}
<nav class="flex gap-1" aria-label="Tipo de auditoria">
    @foreach (['tickets' => ['Tickets', 'auditoria'], 'sistema' => ['Sistema', 'auditoria.sistema']] as $key => [$label, $route])
        <a
            href="{{ route($route) }}"
            wire:navigate
            @if ($active === $key) aria-current="page" @endif
            class="-mb-px border-b-2 px-3 py-2 text-sm {{ $active === $key ? 'border-ink font-medium text-ink' : 'border-transparent text-ink-3 hover:text-ink' }}"
        >{{ $label }}</a>
    @endforeach
</nav>
