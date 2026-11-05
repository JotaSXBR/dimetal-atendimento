{{-- Um cartão por contato compartilhado: iniciais, nome, telefones e, no Inbox, "Adicionar aos contatos" e "Conversar". --}}
<div class="space-y-1.5">
    @foreach (\App\Services\Inbox\SpecialContent::contacts($message->body) as $contact)
        @php
            $waId = \App\Support\PhoneFormat::toWaId($contact['phones'][0] ?? '');
            $known = $waId !== null && \App\Models\Contact::query()->whereIn('wa_id', \App\Support\PhoneFormat::variants($waId))->exists();
        @endphp
        <div data-contact-card class="flex w-64 max-w-full items-center gap-3 rounded-lg border border-line bg-surface p-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-medium text-accent" aria-hidden="true">{{ $contact['initials'] }}</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ $contact['name'] }}</p>
                @foreach ($contact['phones'] as $phone)
                    <p class="truncate font-mono text-xs text-ink-2">{{ $phone }}</p>
                @endforeach
                @if ($waId !== null)
                    @if ($readonly ?? false)
                        <a href="https://wa.me/{{ preg_replace('/\D/', '', $contact['phones'][0]) }}" target="_blank" rel="noopener" class="mt-0.5 inline-block text-xs text-accent underline">Conversar no WhatsApp</a>
                    @else
                        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs">
                            @if ($known)
                                <span class="text-ink-3">Já está nos contatos</span>
                            @else
                                <button type="button" wire:click="addContact({{ $message->id }}, {{ $loop->index }})" class="text-accent underline">Adicionar aos contatos</button>
                            @endif
                            <button type="button" wire:click="chatWith({{ $message->id }}, {{ $loop->index }})" class="text-accent underline">Conversar</button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    @endforeach
</div>
