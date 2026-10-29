{{-- Um cartão por contato compartilhado: iniciais, nome, telefones e atalho para abrir a conversa no WhatsApp. --}}
<div class="space-y-1.5">
    @foreach (\App\Services\Inbox\SpecialContent::contacts($message->body) as $contact)
        @php($digits = preg_replace('/\D/', '', $contact['phones'][0] ?? ''))
        <div data-contact-card class="flex w-64 max-w-full items-center gap-3 rounded-lg border border-line bg-surface p-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-accent-soft text-sm font-medium text-accent" aria-hidden="true">{{ $contact['initials'] }}</span>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium text-ink">{{ $contact['name'] }}</p>
                @foreach ($contact['phones'] as $phone)
                    <p class="truncate font-mono text-xs text-ink-2">{{ $phone }}</p>
                @endforeach
                @if (strlen((string) $digits) >= 10)
                    <a href="https://wa.me/{{ $digits }}" target="_blank" rel="noopener" class="mt-0.5 inline-block text-xs text-accent underline">Conversar no WhatsApp</a>
                @endif
            </div>
        </div>
    @endforeach
</div>
