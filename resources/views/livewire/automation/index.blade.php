<section class="mx-auto w-full max-w-[120rem] space-y-6 p-6">
    <x-page-header title="Automação" subtitle="Workflows do pré-atendimento: a Automação conversa com o cliente e entrega o ticket à fila do setor. Cada número usa um workflow ativo.">
        <flux:button variant="primary" icon="plus" wire:click="$set('showNew', true)">Novo workflow</flux:button>
    </x-page-header>

    @if ($error)
        <div class="rounded-xl border border-warn-line bg-warn-soft px-4 py-2 text-sm text-warn" role="alert">{{ $error }}</div>
    @endif

    @if ($notice)
        <div class="rounded-xl border border-warn-line bg-warn-soft px-4 py-2 text-sm text-warn" role="status">{{ $notice }}</div>
    @endif

    @if ($showNew)
        <x-panel title="Novo workflow">
            <form wire:submit="create" class="space-y-3 p-4">
                <flux:input label="Nome" wire:model="newName" maxlength="120" />
                <fieldset class="space-y-2">
                    <legend class="text-sm font-medium text-ink">Começar de</legend>
                    @foreach (collect($templates)->groupBy('group') as $group => $options)
                        <p class="pt-1 text-xs font-semibold uppercase tracking-wide text-ink-3" wire:key="template-group-{{ $group }}">{{ $group }}</p>
                        @foreach ($options as $option)
                            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-line p-3 text-sm has-[:checked]:border-accent" wire:key="template-{{ $option['key'] }}">
                                <input type="radio" class="mt-1" wire:model="template" value="{{ $option['key'] }}">
                                <span>
                                    <span class="block font-semibold text-ink">{{ $option['name'] }}</span>
                                    <span class="block text-xs text-ink-3">{{ $option['description'] }}</span>
                                </span>
                            </label>
                        @endforeach
                    @endforeach
                </fieldset>
                @error('newName')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">Criar</flux:button>
                    <flux:button type="button" variant="subtle" wire:click="$set('showNew', false)">Cancelar</flux:button>
                </div>
            </form>
        </x-panel>
    @endif

    <x-panel title="Workflows" :subtitle="$workflows->count().' '.($workflows->count() === 1 ? 'workflow' : 'workflows')">
        <ul class="divide-y divide-line">
            @forelse ($workflows as $workflow)
                <li class="px-4 py-3" wire:key="workflow-{{ $workflow->id }}">
                    {{-- basis-72: abaixo de ~18rem livres para o texto, as ações descem para baixo dele. --}}
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                        <div class="min-w-0 grow basis-72 space-y-1">
                            <p class="flex flex-wrap items-center gap-2">
                                <span class="break-words text-[13px] font-semibold text-ink">{{ $workflow->name }}</span>
                                @if ($workflow->versions_count > 0)<x-status-badge tone="ok">Publicado · versão {{ $workflow->versions->first()?->number }}</x-status-badge>@else<x-status-badge tone="neutral">Nunca publicado</x-status-badge>@endif
                            </p>
                            <p class="text-xs text-ink-3">
                                {{ $summaries[$workflow->id]['nodes'] }} {{ $summaries[$workflow->id]['nodes'] === 1 ? 'bloco' : 'blocos' }}@if ($summaries[$workflow->id]['sectors'] > 0) · {{ $summaries[$workflow->id]['sectors'] }} {{ $summaries[$workflow->id]['sectors'] === 1 ? 'setor' : 'setores' }}@endif
                                · rascunho editado {{ $workflow->updated_at?->timezone(config('app.display_timezone'))->format('d/m H:i') }}
                            </p>
                            <div class="flex flex-wrap gap-2">
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
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:button size="sm" icon="pencil-square" :href="route('automacao.editar', $workflow)" wire:navigate>Editar</flux:button>
                            <flux:button size="sm" variant="subtle" wire:click="toggle({{ $workflow->id }})">Versões ({{ $workflow->versions_count }})</flux:button>
                            <flux:button size="sm" variant="subtle" icon="document-duplicate" wire:click="duplicate({{ $workflow->id }})" aria-label="Duplicar {{ $workflow->name }}" />
                            <flux:button size="sm" variant="subtle" icon="trash" wire:click="delete({{ $workflow->id }})" data-confirm="Apagar o workflow {{ $workflow->name }}?" data-confirm-label="Apagar" data-confirm-tone="danger" aria-label="Apagar {{ $workflow->name }}" />
                        </div>
                    </div>

                    @if ($expanded === $workflow->id)
                        <ul class="mt-3 divide-y divide-line rounded-lg border border-line">
                            @forelse ($workflow->versions as $version)
                                <li class="flex flex-wrap items-center gap-3 px-3 py-2 text-xs" wire:key="version-{{ $version->id }}">
                                    <span class="font-semibold text-ink">Versão {{ $version->number }}</span>
                                    <span class="flex-1 text-ink-3">{{ $version->published_at->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }} · {{ $version->publisher?->name ?? 'usuário removido' }}</span>
                                    <flux:button size="xs" variant="subtle" wire:click="restore({{ $workflow->id }}, {{ $version->id }})" data-confirm="Copiar a versão {{ $version->number }} para o rascunho? O rascunho atual será substituído." data-confirm-label="Copiar versão" data-confirm-tone="primary">Restaurar no rascunho</flux:button>
                                </li>
                            @empty
                                <li class="px-3 py-2 text-xs text-ink-3">Ainda não há versões publicadas.</li>
                            @endforelse
                        </ul>
                    @endif
                </li>
            @empty
                <li><x-empty-state icon="bolt" title="Nenhum workflow ainda." description="Crie o primeiro a partir do modelo." /></li>
            @endforelse
        </ul>
    </x-panel>
</section>
