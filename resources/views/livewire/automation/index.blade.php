<x-settings-page route="automacao" title="Automação" subtitle="Workflows do pré-atendimento: o bot conversa com o cliente e entrega o ticket à fila do setor. Cada número usa um workflow ativo." width="5xl">
    <x-slot:actions>
        <flux:button variant="primary" icon="plus" wire:click="$set('showNew', true)">Novo workflow</flux:button>
    </x-slot:actions>

    @if ($error)
        <div class="mt-4 rounded-md border border-warn/30 bg-warn/10 px-4 py-2 text-sm text-warn" role="alert">{{ $error }}</div>
    @endif

    @if ($showNew)
        <form wire:submit="create" class="mt-4 space-y-3 rounded-[14px] border border-line bg-surface p-4">
            <flux:input label="Nome" wire:model="newName" maxlength="120" />
            <flux:checkbox wire:model="fromTemplate" label="Começar do modelo Atendimento padrão (saudação, menu de setores e encaminhamento)" />
            @error('newName')
                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
            @enderror
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Criar</flux:button>
                <flux:button type="button" variant="subtle" wire:click="$set('showNew', false)">Cancelar</flux:button>
            </div>
        </form>
    @endif

    <ul class="mt-6 space-y-3">
        @forelse ($workflows as $workflow)
            <li class="rounded-[14px] border border-line bg-surface p-4" wire:key="workflow-{{ $workflow->id }}">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-semibold text-ink">{{ $workflow->name }}</p>
                        <p class="text-xs text-ink-3">
                            @if ($workflow->versions_count > 0) Publicado: versão {{ $workflow->versions->first()?->number }} @else Nunca publicado @endif
                            · rascunho editado {{ $workflow->updated_at?->timezone(config('app.display_timezone'))->format('d/m H:i') }}
                        </p>
                    </div>
                    <flux:button size="sm" icon="pencil-square" :href="url('automacao/'.$workflow->id)" wire:navigate>Editar</flux:button>
                    <flux:button size="sm" variant="subtle" wire:click="toggle({{ $workflow->id }})">Versões ({{ $workflow->versions_count }})</flux:button>
                    <flux:button size="sm" variant="subtle" icon="document-duplicate" wire:click="duplicate({{ $workflow->id }})" aria-label="Duplicar {{ $workflow->name }}" />
                    <flux:button size="sm" variant="subtle" icon="trash" wire:click="delete({{ $workflow->id }})" wire:confirm="Apagar o workflow {{ $workflow->name }}?" aria-label="Apagar {{ $workflow->name }}" />
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($channels as $channel)
                        @if ($channel->workflow_id === $workflow->id)
                            <flux:button size="xs" variant="primary" icon="check" wire:click="deactivate({{ $channel->id }})">Ativo em {{ $channel->name }}{{ $channel->phoneLabel() ? ' · '.$channel->phoneLabel() : '' }}</flux:button>
                        @else
                            <flux:button size="xs" wire:click="activate({{ $workflow->id }}, {{ $channel->id }})">
                                Ativar em {{ $channel->name }}{{ $channel->phoneLabel() ? ' · '.$channel->phoneLabel() : '' }}@if ($channel->workflow_id !== null) (troca o atual) @endif
                            </flux:button>
                        @endif
                    @endforeach
                </div>

                @if ($expanded === $workflow->id)
                    <ul class="mt-3 divide-y divide-line rounded-md border border-line">
                        @forelse ($workflow->versions as $version)
                            <li class="flex items-center gap-3 px-3 py-2 text-xs" wire:key="version-{{ $version->id }}">
                                <span class="font-semibold text-ink">Versão {{ $version->number }}</span>
                                <span class="flex-1 text-ink-3">{{ $version->published_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} · {{ $version->publisher?->name ?? 'usuário removido' }}</span>
                                <flux:button size="xs" variant="subtle" wire:click="restore({{ $workflow->id }}, {{ $version->id }})" wire:confirm="Copiar a versão {{ $version->number }} para o rascunho? O rascunho atual será substituído.">Restaurar no rascunho</flux:button>
                            </li>
                        @empty
                            <li class="px-3 py-2 text-xs text-ink-3">Ainda não há versões publicadas.</li>
                        @endforelse
                    </ul>
                @endif
            </li>
        @empty
            <li class="rounded-[14px] border border-line bg-surface px-4 py-6 text-center text-sm text-ink-3">Nenhum workflow ainda. Crie o primeiro a partir do modelo.</li>
        @endforelse
    </ul>
</x-settings-page>
