@props(['inset' => false])

@php($edge = $inset ? '-mx-6 -mt-6 mb-6 lg:-mx-8 lg:-mt-8' : '')

{{-- Alertas que só o navegador sabe (sem conexão com o servidor; som bloqueado). As regiões existem antes do texto, para o leitor de tela anunciar. --}}
<div role="alert" aria-live="assertive">
    <div id="alerts-offline" hidden class="{{ $edge }} flex items-center gap-3 bg-danger px-5 py-2.5 text-sm text-white">
        <flux:icon.exclamation-triangle variant="mini" class="size-4 shrink-0" />
        <p><strong>Sem conexão com o servidor</strong> · as mensagens novas não chegam nesta tela até a conexão voltar. Ela tenta de novo sozinha.</p>
    </div>
</div>
<div role="status" aria-live="polite">
    <div id="alerts-audio" hidden class="{{ $edge }} flex flex-wrap items-center gap-3 border-b border-line bg-surface px-5 py-2.5 text-sm text-ink">
        <flux:icon.speaker-x-mark variant="mini" class="size-4 shrink-0" />
        <p class="min-w-0 flex-1"><strong>Som desligado neste navegador</strong> · clique uma vez para o app poder avisar com som quando algo precisar de você.</p>
        <button type="button" id="alerts-audio-enable" class="rounded-lg bg-accent px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Ativar som</button>
    </div>
</div>
