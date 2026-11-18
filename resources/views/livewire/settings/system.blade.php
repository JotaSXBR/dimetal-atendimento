<x-settings-page route="configuracoes.sistema" title="Sistema" subtitle="Versão instalada, novas versões e atualização pelo app.">
    <div class="mt-6 space-y-5" @if ($open) wire:poll.3s data-system-update @endif>
        @if ($open)
            <div data-update-lost hidden role="status" aria-live="polite" class="sticky top-0 z-10 rounded-xl border border-line bg-surface p-4 text-sm text-ink-2 shadow-sm">
                Atualizando. A página volta sozinha quando o sistema subir.
            </div>
        @endif

        <x-panel title="Versão">
            <dl class="text-sm">
                <div class="flex justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <dt class="text-ink-2">Versão instalada</dt>
                    <dd class="font-mono text-xs text-ink">{{ $installed ?? 'desenvolvimento ('.$sha.')' }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <dt class="text-ink-2">Última consulta</dt>
                    <dd class="text-right font-mono text-xs text-ink">
                        @if ($lastCheck === null)
                            Nunca
                        @else
                            {{ $lastCheck['at'] }}@if (! $lastCheck['ok']) · falhou: {{ $lastCheck['error'] }}@endif
                        @endif
                    </dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                    <dt class="text-ink-2">Versão disponível</dt>
                    <dd class="text-right font-mono text-xs text-ink">
                        @if ($target !== null)
                            {{ $target }}
                            @if (str_starts_with($latest['notes_url'], 'https://'))
                                · <a href="{{ $latest['notes_url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-6 items-center font-medium text-accent-content underline underline-offset-2">Ver novidades</a>
                            @endif
                        @else
                            Nenhuma versão nova
                        @endif
                    </dd>
                </div>
            </dl>
        </x-panel>

        <x-panel title="Consulta de novas versões" subtitle="O sistema pergunta ao GitHub, uma vez por dia, se há uma Release nova. Nenhum dado desta instalação é enviado.">
            <div class="flex flex-wrap gap-2 p-4">
                <flux:button wire:click="toggleCheck">{{ $checkEnabled ? 'Desligar a consulta' : 'Ligar a consulta' }}</flux:button>
                <flux:button wire:click="checkNow" wire:loading.attr="disabled" wire:target="checkNow">Consultar agora</flux:button>
            </div>
        </x-panel>

        @if ($installed !== null && $hasService)
            <x-panel title="Conexão com o Coolify" subtitle="O token precisa das permissões read, read:sensitive, write e deploy (nunca delete).">
                <div class="p-4">
                    <form wire:submit="saveConnection" class="max-w-md space-y-3">
                        <flux:input wire:model="panelUrl" label="URL do painel" type="url" placeholder="https://coolify.suaempresa.com.br" autocomplete="off" />
                        @if ($tokenSaved)
                            <p class="text-sm text-ink-2">Token salvo{{ $tokenSavedAt ? ' em '.$tokenSavedAt : '' }}. Cole outro abaixo para trocar.</p>
                        @endif
                        <flux:input wire:model="tokenInput" label="{{ $tokenSaved ? 'Trocar o token' : 'Token da API' }}" type="password" autocomplete="off" />
                        <div class="flex flex-wrap gap-2">
                            <flux:button type="submit" variant="primary">Salvar</flux:button>
                            @if ($tokenSaved)
                                <flux:button type="button" wire:click="removeToken" data-confirm="Remover o token do Coolify? A atualização pelo app para de funcionar até colar outro." data-confirm-label="Remover" data-confirm-tone="danger">Remover o token</flux:button>
                            @endif
                        </div>
                    </form>

                    <div class="mt-4">
                        <flux:button wire:click="testConnection" wire:loading.attr="disabled" wire:target="testConnection">Testar conexão</flux:button>
                        @if ($connectionMessage !== null)
                            <p class="mt-3 text-sm text-ok" role="status">{{ $connectionMessage }}</p>
                        @endif
                        @foreach ($connectionErrors as $error)
                            <p class="mt-3 text-sm text-danger" role="alert">{{ $error }}</p>
                        @endforeach
                    </div>
                </div>
            </x-panel>
        @elseif ($installed !== null)
            <div class="rounded-xl border border-line bg-surface p-4 text-sm text-ink-2" role="status">
                Esta instalação não é um Service do Coolify (falta <span class="font-mono">COOLIFY_RESOURCE_UUID</span>), então a atualização pelo app não está disponível.
            </div>
        @else
            <div class="rounded-xl border border-line bg-surface p-4 text-sm text-ink-2" role="status">
                Versão de desenvolvimento: a atualização pelo app só vale para uma Release instalada.
            </div>
        @endif

        @if ($last !== null)
            <x-panel title="Atualização para {{ $last->to_version }}">
                @if ($open)
                <ol class="text-sm">
                    @foreach ($steps as $step)
                        <li data-step data-state="{{ $step['state'] }}" class="flex items-center justify-between gap-4 border-b border-line px-4 py-3 last:border-b-0">
                            <span class="text-ink">{{ $step['label'] }}</span>
                            <span class="font-mono text-xs {{ match ($step['state']) { 'done' => 'text-ok', 'running' => 'text-accent-content', default => 'text-ink-3' } }}">
                                {{ match ($step['state']) { 'done' => 'feito', 'running' => 'em andamento…', default => 'aguardando' } }}
                            </span>
                        </li>
                    @endforeach
                </ol>
                @endif
                @if ($open)
                    @if ($stale)
                        <p class="border-t border-line px-4 py-3 text-sm text-danger" role="alert">Sem confirmação há mais de 30 minutos. Veja a recuperação em docs/deploy.md.</p>
                    @endif
                @else
                    <div class="p-4">
                        <x-status-badge :tone="$last->status === \App\Enums\UpdateStatus::Concluida ? 'ok' : 'danger'">{{ $last->status->label() }}</x-status-badge>
                        @if ($last->message)
                            <p class="mt-2 text-sm break-words text-ink-2">{{ $last->message }}</p>
                        @endif
                    </div>
                @endif
            </x-panel>
        @endif

        @if ($problem !== null)
            <p class="text-sm text-danger" role="alert">{{ $problem }}</p>
        @endif

        @if ($canUpdate && $target !== null && ! $open)
            @if ($confirming)
                <x-panel x-data="updateConfirm" title="Atualizar para {{ $target }}">
                    <div class="p-4">
                        <div class="space-y-2 text-sm text-ink-2">
                            <p>O sistema faz um backup do banco, grava a nova versão no Coolify e reinicia. Fica fora do ar por alguns minutos.</p>
                            <p>Se a troca não acontecer, o sistema desfaz sozinho. Se a versão nova subir com erro, siga a recuperação em docs/deploy.md (Atualizar → Rollback).</p>
                            @if ($composeEdited)
                                <p class="text-danger">O compose deste Service no Coolify foi editado à mão. A atualização o substitui pelo da Release; confira se há algo seu para refazer depois.</p>
                            @endif
                        </div>
                        <div class="mt-4 max-w-xs">
                            <flux:input wire:model.live.debounce.250ms="confirm" label="Digite {{ $confirmWord }} para confirmar" autocomplete="off" />
                        </div>
                        <div class="mt-4 flex flex-wrap gap-2">
                            <flux:button variant="danger" wire:click="update" wire:loading.attr="disabled" wire:target="update" :disabled="$confirm !== $confirmWord">Atualizar agora</flux:button>
                            <flux:button wire:click="cancelUpdate">Cancelar</flux:button>
                        </div>
                    </div>
                </x-panel>
            @else
                <flux:button variant="primary" wire:click="askUpdate" wire:loading.attr="disabled" wire:target="askUpdate">Atualizar para {{ $target }}</flux:button>
            @endif
        @endif
    </div>
</x-settings-page>
