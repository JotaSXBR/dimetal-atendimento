<section class="mx-auto w-full max-w-3xl space-y-6 p-6">
    <div>
        <flux:heading size="xl" level="1">Retenção</flux:heading>
        <flux:subheading>O texto das mensagens, o protocolo e a linha do tempo ficam para sempre. Só os arquivos têm prazo.</flux:subheading>
    </div>

    <dl class="divide-y divide-line rounded-[14px] border border-line bg-surface text-sm">
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Anexos e mídia</dt>
            <dd class="font-mono text-ink">{{ $days }} dias</dd>
        </div>
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Espaço usado pelos anexos guardados</dt>
            <dd class="font-mono text-ink">{{ number_format($bytes / 1_000_000, 1, ',', '.') }} MB em {{ $files }} {{ $files === 1 ? 'anexo' : 'anexos' }}</dd>
        </div>
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Passaram do prazo</dt>
            <dd class="font-mono text-ink">{{ $expired }} já passou{{ $expired === 1 ? '' : 'ram' }} do prazo</dd>
        </div>
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Expurgo automático</dt>
            <dd class="font-mono text-ink">todo dia às 03:30</dd>
        </div>
    </dl>

    <div class="rounded-[14px] border border-line bg-surface p-4">
        <p class="text-sm font-semibold text-ink">Apagar agora a mídia com mais de {{ $days }} dias</p>
        <p class="mt-1 text-xs text-ink-3">Não afeta texto, protocolos nem relatórios. Cada arquivo apagado fica registrado na auditoria.</p>
        <flux:button class="mt-3" variant="danger" wire:click="purgeNow" wire:confirm="Apagar agora os anexos com mais de {{ $days }} dias? Isso não pode ser desfeito.">Executar expurgo agora</flux:button>
        @if ($result !== '')
            <p class="mt-3 text-sm text-ink-2" role="status">{{ $result }}</p>
        @endif
    </div>
</section>
