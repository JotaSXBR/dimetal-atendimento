<x-settings-page route="configuracoes.retencao" title="Anexos e retenção" subtitle="O texto das mensagens, o protocolo e a linha do tempo ficam para sempre. Só os arquivos têm prazo.">
    <div class="mt-6 space-y-5">
        <x-panel title="Prazo e uso">
            <dl class="text-sm">
                <div class="flex justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <dt class="text-ink-2">Anexos e mídia</dt>
                    <dd class="text-right font-mono text-xs text-ink">{{ $days }} dias</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <dt class="text-ink-2">Espaço usado pelos anexos guardados</dt>
                    <dd class="text-right font-mono text-xs text-ink">{{ number_format($bytes / 1_000_000, 1, ',', '.') }} MB em {{ $files }} {{ $files === 1 ? 'anexo' : 'anexos' }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <dt class="text-ink-2">Passaram do prazo</dt>
                    <dd class="text-right font-mono text-xs text-ink">{{ $expired }} já passou{{ $expired === 1 ? '' : 'ram' }} do prazo</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <dt class="text-ink-2">Expurgo automático</dt>
                    <dd class="text-right font-mono text-xs text-ink">todo dia às 03:30</dd>
                </div>
            </dl>
        </x-panel>

        <x-panel :title="'Apagar agora a mídia com mais de '.$days.' dias'" subtitle="Não afeta texto, protocolos nem relatórios. Cada arquivo apagado fica registrado na auditoria.">
            <div class="p-4">
                <flux:button variant="danger" wire:click="purgeNow" data-confirm="Apagar agora os anexos com mais de {{ $days }} dias? Isso não pode ser desfeito." data-confirm-label="Apagar agora" data-confirm-tone="danger">Executar expurgo agora</flux:button>
                @if ($result !== '')
                    <p class="mt-3 text-sm text-ink-2" role="status">{{ $result }}</p>
                @endif
            </div>
        </x-panel>
    </div>
</x-settings-page>
