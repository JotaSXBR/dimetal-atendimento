<?php

namespace App\Livewire\Inbox;

use App\Actions\Contacts\CreateContact;
use App\Actions\Inbox\StartConversation;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\ConversationHandoff;
use App\Services\Inbox\ConversationNotStarted;
use App\Support\PhoneFormat;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;

/** A janela "Nova conversa": aberta pelo evento `start-conversation` (Fila, Contatos, ficha e cartão de contato recebido). */
class NewConversation extends Component
{
    public ?int $contactId = null;

    public string $search = '';

    public string $phone = '';

    public string $name = '';

    public ?int $channelId = null;

    public ?int $sectorId = null;

    public string $text = '';

    /** Por que a conversa não começou (a mensagem vem da ação, pronta para a tela). */
    public ?string $failure = null;

    #[On('start-conversation')]
    public function open(?int $contactId = null): void
    {
        $this->reset('search', 'phone', 'name', 'text', 'failure', 'channelId', 'sectorId');
        $this->resetErrorBag();
        $this->contactId = null;

        if ($contactId !== null) {
            $open = Ticket::query()->open()->readableBy($this->user())->where('contact_id', $contactId)->orderByDesc('opened_at')->orderByDesc('id')->first();

            // Já há conversa aberta que o usuário vê: não faz sentido pedir canal, setor e mensagem; leva direto a ela.
            if ($open !== null) {
                $this->flashExisting($open, Contact::query()->findOrFail($contactId));
                $this->redirectRoute('inbox', ['ticket' => $open->id]);

                return;
            }

            $this->pick($contactId);
        } else {
            $this->preselect(null);
        }

        $this->dispatch('modal-show', name: 'new-conversation');
    }

    public function pick(int $id): void
    {
        $contact = Contact::query()->findOrFail($id);

        $this->contactId = $contact->id;
        $this->search = '';
        $this->preselect($contact);
    }

    public function clearContact(): void
    {
        $this->contactId = null;
    }

    public function submit(StartConversation $start, CreateContact $createContact): void
    {
        $this->failure = null;
        $this->resetErrorBag();

        $this->validate(
            ['channelId' => ['required', 'integer'], 'sectorId' => ['required', 'integer'], 'text' => ['required', 'string', 'max:'.StartConversation::TEXT_LIMIT]],
            ['channelId.required' => 'Escolha o canal.', 'sectorId.required' => 'Escolha o setor.', 'text.required' => 'Escreva a primeira mensagem.', 'text.max' => 'A mensagem passa de '.StartConversation::TEXT_LIMIT.' caracteres.'],
        );

        $channel = Channel::query()->findOrFail($this->channelId);
        $sector = Sector::query()->findOrFail($this->sectorId);

        try {
            $contact = $this->contactId !== null ? Contact::query()->findOrFail($this->contactId) : $this->newContact($start, $createContact, $channel);

            if ($contact === null) {
                return;
            }

            $result = $start($this->user(), $contact, $channel, $sector, $this->text);
        } catch (ConversationNotStarted $e) {
            $this->failure = $e->getMessage();

            return;
        }

        if (! $result['created']) {
            $this->flashExisting($result['ticket'], $contact);
        }

        $this->redirectRoute('inbox', ['ticket' => $result['ticket']->id]);
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.inbox.new-conversation', [
            'contact' => $this->contactId === null ? null : Contact::query()->find($this->contactId),
            'matches' => $this->contactId === null && mb_strlen(trim($this->search)) >= 2 ? Contact::query()->with('tags')->search($this->search)->limit(8)->get() : new Collection,
            'channels' => Channel::query()->where('active', true)->orderBy('name')->get(),
            'sectors' => $this->sectorsFor($user),
        ]);
    }

    /** Telefone digitado: confere no WhatsApp antes de cadastrar, para um número sem conta não virar contato. */
    private function newContact(StartConversation $start, CreateContact $createContact, Channel $channel): ?Contact
    {
        $waId = PhoneFormat::toWaId($this->phone);

        if ($waId === null) {
            $this->addError('phone', 'Telefone inválido: informe o DDD e o número.');

            return null;
        }

        $known = Contact::query()->whereIn('wa_id', PhoneFormat::variants($waId))->first();

        if ($known !== null) {
            return $known;
        }

        $start->assertHasWhatsApp($waId, $channel);

        try {
            return $createContact($this->phone, $this->name)['contact'];
        } catch (ValidationException $e) {
            $this->addError('phone', $e->validator->errors()->first('phone') ?: 'Telefone inválido.');

            return null;
        }
    }

    /** Já havia conversa aberta: o aviso diz de quem é e, se o usuário pode responder, o texto digitado vai para o campo dele. */
    private function flashExisting(Ticket $ticket, Contact $contact): void
    {
        $owner = $ticket->assignee->name ?? 'a fila';
        $text = trim($this->text);
        // Sem texto digitado (o desvio do `open` leva direto ao ticket) não há mensagem para levar ao campo.
        $carriesText = $text !== '' && $this->user()->can('reply', $ticket);

        ConversationHandoff::flash('Já existe uma conversa aberta com '.$contact->label().' (de '.$owner.').'.($carriesText ? ' Sua mensagem está no campo de resposta.' : ''), $text, $carriesText);
    }

    /** Canal do ticket mais recente do contato (ou o único ativo) e o setor único do atendente já vêm preenchidos. */
    private function preselect(?Contact $contact): void
    {
        $active = Channel::query()->where('active', true)->get();
        $latest = $contact === null ? null : Ticket::query()->where('contact_id', $contact->id)->latest('opened_at')->first()?->channel_id;

        $this->channelId = $latest !== null && $active->contains('id', $latest) ? $latest : ($active->count() === 1 ? $active->first()?->id : null);

        $sectors = $this->sectorsFor($this->user());
        $this->sectorId = $sectors->count() === 1 ? $sectors->first()?->id : null;
    }

    /**
     * Setores em que o usuário pode iniciar: supervisor e admin, todos que recebem tickets; atendente, os que cobre.
     *
     * @return Collection<int, Sector>
     */
    private function sectorsFor(User $user): Collection
    {
        $query = $user->canSeeAllSectors() ? Sector::query() : $user->sectors();

        return $query->receivingTickets()->orderBy('menu_order')->get();
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }
}
