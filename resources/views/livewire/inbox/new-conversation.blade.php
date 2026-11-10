{{-- Janela "Nova conversa": contato (da lista ou telefone novo), canal, setor e a primeira mensagem. Ver App\Actions\Inbox\StartConversation. --}}
{{-- `absolute`: a raiz fica fora do fluxo, senão vira uma célula a mais na grade do Inbox e empurra os detalhes para baixo da fila. --}}
<div class="absolute">
    <flux:modal name="new-conversation" class="w-full max-w-lg">
        <form wire:submit="submit" class="space-y-4">
            <div>
                <flux:heading size="lg">Nova conversa</flux:heading>
                <flux:subheading>O ticket nasce com a primeira mensagem e fica com você.</flux:subheading>
            </div>

            @if ($contact)
                <div class="flex items-center gap-3 rounded-lg border border-line bg-canvas px-3 py-2" data-testid="picked-contact">
                    <x-avatar :src="$contact->avatarUrl()" :name="$contact->label()" shape="round" size="lg" decorative />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink">{{ $contact->label() }}</span>
                        <span class="block truncate text-xs text-ink-3">{{ $contact->phoneLabel() }}</span>
                    </span>
                    <flux:button type="button" size="xs" variant="subtle" wire:click="clearContact">Trocar</flux:button>
                </div>
            @else
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Buscar contato por nome, telefone ou e-mail" />

                @if ($matches->isNotEmpty())
                    <ul class="divide-y divide-line overflow-hidden rounded-lg border border-line" role="listbox" aria-label="Contatos">
                        @foreach ($matches as $match)
                            <li wire:key="match-{{ $match->id }}">
                                <button type="button" wire:click="pick({{ $match->id }})" class="flex w-full items-center gap-2 px-3 py-2 text-start hover:bg-canvas">
                                    <span class="truncate text-sm font-medium text-ink">{{ $match->label() }}</span>
                                    <span class="ms-auto shrink-0 font-mono text-xs text-ink-3">{{ $match->phoneLabel() }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="grid grid-cols-2 gap-3">
                    <flux:input wire:model="phone" label="Ou um telefone novo" placeholder="(16) 99999-9999" />
                    <flux:input wire:model="name" label="Nome (opcional)" />
                </div>
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <flux:select wire:model="channelId" label="Canal">
                        <flux:select.option value="">Escolha o canal</flux:select.option>
                        @foreach ($channels as $channel)
                            <flux:select.option :value="$channel->id" :disabled="! $channel->isConnected()">{{ $channel->name }}@unless ($channel->isConnected()) (fora do ar)@endunless</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <div>
                    <flux:select wire:model="sectorId" label="Setor">
                        <flux:select.option value="">Escolha o setor</flux:select.option>
                        @foreach ($sectors as $sector)
                            <flux:select.option :value="$sector->id">{{ $sector->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div>
                <flux:textarea wire:model="text" label="Primeira mensagem" rows="3" placeholder="Escreva a mensagem que abre a conversa" />
            </div>

            @if ($failure)
                <p class="rounded-md bg-danger/10 px-3 py-2 text-sm text-danger" role="alert">{{ $failure }}</p>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">Cancelar</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" icon="paper-airplane">Iniciar conversa</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
