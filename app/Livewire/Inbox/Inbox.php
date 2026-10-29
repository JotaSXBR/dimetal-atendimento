<?php

namespace App\Livewire\Inbox;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\CloseTicket;
use App\Actions\Inbox\ReassignTicket;
use App\Actions\Inbox\TakeoverTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\ResendTicketMessage;
use App\Services\Inbox\SendTicketMedia;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Atendimento')]
#[Layout('layouts::inbox')]
class Inbox extends Component
{
    use WithFileUploads;

    /** Prévia da lista: o texto ou, quando não há, o nome do tipo de conteúdo. */
    private const PREVIEW_SQL = "case when type = 'location' then 'Localização' when type = 'contact' then 'Contato' "
        ."when body <> '' then body when type = 'view_once' then 'Visualização única' "
        ."when media_mime = 'image/webp' then 'Figurinha' when media_mime like 'image/%' then 'Foto' "
        ."when media_mime like 'audio/%' then 'Áudio' when media_mime like 'video/%' then 'Vídeo' "
        ."when type = 'media' then 'Arquivo' else '['||type||']' end";

    /** Legenda de mídia no WhatsApp: acima disto o texto sai como mensagem separada. */
    private const CAPTION_LIMIT = 1024;

    public ?int $ticketId = null;

    #[Url]
    public string $tab = 'todos';

    public string $draft = '';

    /** O arquivo que acabou de subir; `commitUpload` o move para a bandeja ou o descarta. */
    public ?TemporaryUploadedFile $upload = null;

    /** @var array<int, TemporaryUploadedFile> Bandeja: arquivos aceitos, na ordem de envio. */
    public array $attachments = [];

    public ?string $notice = null;

    /** Painel aberto sobre a conversa: `transfer`, `reassign` ou nenhum. */
    public ?string $panel = null;

    public ?int $transferSectorId = null;

    public string $transferNote = '';

    public ?int $reassignUserId = null;

    public function select(int $ticketId): void
    {
        $this->authorize('view', Ticket::findOrFail($ticketId));

        $this->ticketId = $ticketId;
        $this->notice = null;
        $this->discardAttachments();
        $this->closePanel();
    }

    public function claim(): void
    {
        // Sem o filtro de visibilidade: quem perde a corrida ainda precisa receber o aviso, não um 404.
        $ticket = Ticket::findOrFail($this->ticketId);

        if (! $this->user()->can('claim', $ticket) || ! (new ClaimTicket)($ticket, $this->user())) {
            $this->notice = 'Outra pessoa assumiu este ticket antes de você.';
        }
    }

    /**
     * `$seenAssignee` é o responsável que estava na tela: se o ticket mudou de mão no meio do caminho, a troca
     * não acontece e o usuário recebe o aviso.
     */
    public function takeover(int $seenAssignee): void
    {
        $ticket = $this->selected();
        $this->authorize('takeover', $ticket);

        $this->notice = (new TakeoverTicket)($ticket, $this->user(), $seenAssignee)
            ? null
            : 'Este ticket mudou de responsável antes de você tomá-lo.';
    }

    public function openPanel(string $panel): void
    {
        $ticket = $this->selected();
        $this->authorize($panel === 'reassign' ? 'reassign' : 'transfer', $ticket);

        $this->panel = $panel === 'reassign' ? 'reassign' : 'transfer';
        $this->resetErrorBag();
    }

    public function closePanel(): void
    {
        $this->reset('panel', 'transferSectorId', 'transferNote', 'reassignUserId');
        $this->resetErrorBag();
    }

    public function transfer(): void
    {
        $ticket = $this->selected();
        $this->authorize('transfer', $ticket);

        $this->validate([
            'transferSectorId' => ['required', 'integer', Rule::exists('sectors', 'id')->whereIn('status', $this->receivingStatuses())],
            'transferNote' => ['nullable', 'string', 'max:500'],
        ], [
            'transferSectorId.required' => 'Escolha o setor de destino.',
            'transferSectorId.exists' => 'Esse setor não recebe tickets.',
            'transferNote.max' => 'A nota passa de 500 caracteres.',
        ]);

        $moved = (new TransferTicket)($ticket, $this->user(), Sector::findOrFail($this->transferSectorId), $this->transferNote);

        $this->notice = $moved ? null : 'Não foi possível transferir: o ticket já está nesse setor ou foi encerrado.';
        $this->closePanel();
    }

    public function reassign(): void
    {
        $ticket = $this->selected();
        $this->authorize('reassign', $ticket);

        $this->validate(
            ['reassignUserId' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)]],
            ['reassignUserId.required' => 'Escolha quem fica com o ticket.'],
        );

        $reassigned = (new ReassignTicket)($ticket, $this->user(), User::findOrFail($this->reassignUserId));

        $this->notice = $reassigned ? null : 'Não foi possível reatribuir: essa pessoa não atende este setor ou já está com o ticket.';
        $this->closePanel();
    }

    public function close(): void
    {
        $ticket = $this->selected();
        $this->authorize('close', $ticket);

        (new CloseTicket)($ticket, $this->user());
    }

    /** Entra na bandeja o arquivo que acabou de subir (um por requisição); o servidor decide se ele fica. */
    public function commitUpload(): void
    {
        $this->authorize('reply', $this->selected());
        $this->resetErrorBag('attachments');

        $file = $this->upload;
        $this->upload = null;

        if ($file === null) {
            return;
        }

        $error = count($this->attachments) >= AttachmentRules::MAX_FILES
            ? 'No máximo '.AttachmentRules::MAX_FILES.' arquivos por envio.'
            : AttachmentRules::validate($file);

        if ($error !== null) {
            $this->addError('attachments', $error);
            $file->delete();

            return;
        }

        $this->attachments[] = $file;
    }

    public function removeAttachment(int $index): void
    {
        $file = $this->attachments[$index] ?? null;

        if ($file !== null) {
            $file->delete();
            unset($this->attachments[$index]);
            $this->attachments = array_values($this->attachments);
        }
    }

    /**
     * Envia o texto e/ou os arquivos da bandeja. Cada arquivo é uma mensagem; o texto vira a legenda do primeiro, exceto
     * quando o primeiro é áudio (voz não tem legenda) ou a legenda passa de 1024 caracteres: nesses casos sai antes, como
     * mensagem própria. A ordem final é a da fila de saída.
     */
    public function send(): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);

        $this->draft = trim($this->draft);
        $this->resetErrorBag();

        if ($this->attachments === []) {
            $this->validate(['draft' => ['required', 'string', 'max:4096']], [
                'draft.required' => 'Escreva uma mensagem.',
                'draft.max' => 'A mensagem passa de 4096 caracteres.',
            ]);
        } else {
            $this->validate(['draft' => ['nullable', 'string', 'max:4096']], ['draft.max' => 'A mensagem passa de 4096 caracteres.']);
        }

        $captioned = $this->attachments !== [] && $this->draft !== '' && $this->captionFits($this->attachments[0]);
        $cost = count($this->attachments) + ($this->draft !== '' && ! $captioned ? 1 : 0);

        // Freio contra conta comprometida ou laço no navegador: cada mensagem enviada conta, arquivo inclusive.
        if (! $this->reserveSends($cost)) {
            $this->addError('draft', 'Você está enviando mensagens rápido demais. Aguarde alguns segundos.');

            return;
        }

        if ($this->draft !== '' && ! $captioned) {
            SendTicketMessageJob::dispatch(app(SendTicketMessage::class)->create($ticket, $this->user(), $this->draft)->id);
        }

        $failures = [];

        foreach ($this->attachments as $position => $file) {
            try {
                app(SendTicketMedia::class)($ticket, $this->user(), $file, $captioned && $position === 0 ? $this->draft : '');
            } catch (InvalidArgumentException $e) {
                $failures[] = $e->getMessage();
            }

            $file->delete();
        }

        $this->reset('draft', 'attachments');

        foreach ($failures as $failure) {
            $this->addError('attachments', $failure);
        }
    }

    private function discardAttachments(): void
    {
        foreach ($this->attachments as $file) {
            $file->delete();
        }

        $this->reset('attachments', 'upload');
    }

    private function captionFits(TemporaryUploadedFile $first): bool
    {
        $info = AttachmentRules::inspect($first);

        return is_array($info) && $info['kind'] !== 'audio' && mb_strlen($this->draft) <= self::CAPTION_LIMIT;
    }

    private function reserveSends(int $count): bool
    {
        $key = 'send-message:'.$this->user()->id;
        $max = config()->integer('security.send_message_per_minute');

        if (RateLimiter::remaining($key, $max) < $count) {
            return false;
        }

        for ($i = 0; $i < $count; $i++) {
            RateLimiter::hit($key, 60);
        }

        return true;
    }

    /**
     * Reenvia, na mesma linha, uma mensagem que falhou. Falha que pode ter saído exige confirmação (o botão pede e chama de
     * novo com `$confirmed`); o servidor confere de novo, porque a tela sozinha não protege contra mensagem em dobro.
     */
    public function resend(int $messageId, bool $confirmed = false): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);

        $message = Message::query()->where('ticket_id', $ticket->id)->findOrFail($messageId);

        $this->notice = match ((new ResendTicketMessage)($message, $this->user(), $confirmed)) {
            ResendTicketMessage::CONFIRM => 'Confirme antes de reenviar: a mensagem pode já ter sido enviada.',
            ResendTicketMessage::NONE => 'Esta mensagem não pode ser reenviada.',
            default => null,
        };
    }

    /**
     * Qualquer evento do inbox só pede nova leitura do banco; o texto nunca viaja pelo websocket.
     */
    #[On('echo-private:inbox,.ticket.updated')]
    #[On('echo-private:inbox,.message.created')]
    #[On('echo-private:inbox,.message.status')]
    public function refresh(): void {}

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * O ticket aberto na tela, desde que o usuário ainda possa vê-lo (outro atendente pode ter assumido).
     */
    private function selected(): Ticket
    {
        return Ticket::query()->visibleTo($this->user())->findOrFail($this->ticketId);
    }

    private function find(): ?Ticket
    {
        return $this->ticketId === null
            ? null
            : Ticket::query()->visibleTo($this->user())->with(['contact', 'channel', 'assignee', 'sector'])->find($this->ticketId);
    }

    /**
     * @return list<string>
     */
    private function receivingStatuses(): array
    {
        return array_values(array_map(
            fn (SectorStatus $status): string => $status->value,
            array_filter(SectorStatus::cases(), fn (SectorStatus $status): bool => $status->receivesTickets()),
        ));
    }

    /**
     * Quem pode receber o ticket numa reatribuição: usuários ativos do setor dele (ou todos, se não tem setor).
     *
     * @return Collection<int, User>
     */
    private function reassignCandidates(Ticket $ticket): Collection
    {
        return User::query()
            ->where('active', true)
            ->when($ticket->sector_id !== null, fn (Builder $q) => $q->whereHas('sectors', fn (Builder $s) => $s->whereKey($ticket->sector_id)))
            ->when($ticket->assigned_user_id !== null, fn (Builder $q) => $q->whereKeyNot($ticket->assigned_user_id))
            ->orderBy('name')
            ->get();
    }

    public function render(): View
    {
        $user = $this->user();
        $ticket = $this->find();

        $list = Ticket::query()
            ->open()
            ->visibleTo($user)
            ->with('contact')
            ->addSelect(['preview' => Message::query()
                ->selectRaw(self::PREVIEW_SQL)->whereColumn('ticket_id', 'tickets.id')->latest('id')->limit(1)])
            ->when($this->tab === 'aguardando', fn (Builder $q) => $q->where('status', TicketStatus::AGUARDANDO->value))
            ->when($this->tab === 'meus', fn (Builder $q) => $q->where('assigned_user_id', $user->id))
            ->orderBy('last_inbound_at')
            ->get();

        return view('livewire.inbox.inbox', [
            'waiting' => $list->where('status', TicketStatus::AGUARDANDO),
            'active' => $list->where('status', TicketStatus::EM_ATENDIMENTO),
            'ticket' => $ticket,
            'messages' => $ticket?->messages()->latest('id')->limit(200)->get()->reverse(),
            'canClaim' => $ticket !== null && $user->can('claim', $ticket),
            'canClose' => $ticket !== null && $user->can('close', $ticket),
            'canReply' => $ticket !== null && $user->can('reply', $ticket),
            'canTransfer' => $ticket !== null && $user->can('transfer', $ticket),
            'canReassign' => $ticket !== null && $user->can('reassign', $ticket),
            'canTakeover' => $ticket !== null && $user->can('takeover', $ticket),
            'sectors' => $this->panel === 'transfer' ? Sector::query()->receivingTickets()->orderBy('menu_order')->get() : collect(),
            'candidates' => $ticket !== null && $this->panel === 'reassign' ? $this->reassignCandidates($ticket) : collect(),
        ]);
    }
}
