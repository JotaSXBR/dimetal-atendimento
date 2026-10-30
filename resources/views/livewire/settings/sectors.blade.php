<section class="mx-auto w-full max-w-3xl p-6">
    <x-page-header title="Setores" subtitle="Ativo aparece no menu e recebe tickets. Fora do menu não aparece no menu, mas continua recebendo transferências. Desativado não recebe nada novo; o histórico permanece." />

    <ul class="mt-6 divide-y divide-line rounded-[14px] border border-line bg-surface">
        @foreach ($sectors as $sector)
            <li class="px-4 py-3" wire:key="sector-{{ $sector->id }}">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex flex-col">
                        <flux:button size="xs" variant="subtle" icon="chevron-up" wire:click="move({{ $sector->id }}, 'up')" aria-label="Subir {{ $sector->name }}" />
                        <flux:button size="xs" variant="subtle" icon="chevron-down" wire:click="move({{ $sector->id }}, 'down')" aria-label="Descer {{ $sector->name }}" />
                    </div>

                    <p class="flex-1 text-sm font-semibold text-ink">
                        <span class="me-2 font-mono text-xs text-ink-3">{{ $loop->iteration }}</span>{{ $sector->name }}
                    </p>

                    <div class="w-44">
                        <flux:select :value="$sector->status->value" wire:change="setStatus({{ $sector->id }}, $event.target.value)" aria-label="Estado de {{ $sector->name }}">
                            @foreach ($statuses as $status)
                                <flux:select.option :value="$status->value" :selected="$status === $sector->status">{{ $status->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                </div>

                @error('status.'.$sector->id)
                    <p class="mt-2 text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
            </li>
        @endforeach
    </ul>
</section>
