{{-- "Conversar": abre a janela Nova conversa com este contato. Só com telefone de WhatsApp (`@c.us`); contato só com `lid` fica desabilitado. --}}
@if (str_ends_with((string) $contact->wa_id, '@c.us'))
    <flux:button type="button" size="sm" variant="subtle" icon="chat-bubble-left-right" :class="$class ?? ''" x-on:click.stop="$dispatch('start-conversation', { contactId: {{ $contact->id }} })">Conversar</flux:button>
@else
    <flux:button type="button" size="sm" variant="subtle" icon="chat-bubble-left-right" :class="$class ?? ''" disabled data-testid="chat-disabled" title="Sem telefone: só dá para conversar com quem tem número de WhatsApp">Conversar</flux:button>
@endif
