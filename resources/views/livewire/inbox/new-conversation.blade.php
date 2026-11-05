{{-- Janela "Nova conversa": contato (da lista ou telefone novo), canal, setor e a primeira mensagem. Ver App\Actions\Inbox\StartConversation. --}}
<div>
    <flux:modal name="new-conversation" class="w-full max-w-lg">
        <form wire:submit="submit" class="space-y-4">
            <div>
                <flux:heading size="lg">Nova conversa</flux:heading>
                <flux:subheading>O ticket nasce com a primeira mensagem e fica com você.</flux:subheading>
            </div>

            @if ($contact)
                <div class="flex items-center gap-3 rounded-lg border border-line bg-canvas px-3 py-2" data-testid="picked-contact">
                    <flux:avatar :name="$contact->label()" size="sm" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink">{{ $contact->label() }}</span>
                        <span class="block truncate text-xs text-ink-3">{{ $contact->phoneLabel() }}</span>
                    </span>
                    <button type="button" wire:click="clearContact" class="text-xs text-ink-3 hover:text-ink">Trocar</button>
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
                @error('phone')
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
            @endif

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <flux:select wire:model="channelId" label="Canal" placeholder="Escolha o canal">
                        @foreach ($channels as $channel)
                            <flux:select.option :value="$channel->id" :disabled="! $channel->isConnected()">{{ $channel->name }}@unless ($channel->isConnected()) (fora do ar)@endunless</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('channelId')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <flux:select wire:model="sectorId" label="Setor" placeholder="Escolha o setor">
                        @foreach ($sectors as $sector)
                            <flux:select.option :value="$sector->id">{{ $sector->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('sectorId')
                        <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                    @enderror
                </div>
            </div>

            <div>
                <flux:textarea wire:model="text" label="Primeira mensagem" rows="3" placeholder="Escreva a mensagem que abre a conversa" />
                @error('text')
                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                @enderror
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
