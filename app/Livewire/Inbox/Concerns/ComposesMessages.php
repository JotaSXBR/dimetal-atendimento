<?php

namespace App\Livewire\Inbox\Concerns;

use App\Jobs\SendTicketMessageJob;
use App\Models\QuickReply;
use App\Models\Ticket;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\Presence;
use App\Services\Inbox\SendTicketMedia;
use App\Services\Inbox\SendTicketMessage;
use App\Services\Inbox\Signature;
use App\Services\Waha\WahaClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A barra de envio do inbox: texto, bandeja de arquivos e gravador de voz. O componente que usa precisa oferecer
 * `selected()` (o ticket na tela, já filtrado pela visibilidade) e `user()`.
 */
trait ComposesMessages
{
    use WithFileUploads;

    /** Legenda de mídia no WhatsApp: acima disto o texto sai como mensagem separada. */
    private const CAPTION_LIMIT = 1024;

    /** "Gravando áudio…" chega a cada poucos segundos; limite próprio, mais folgado que o de envio. */
    private const PRESENCE_PER_MINUTE = 20;

    public string $draft = '';

    /** Assinar as mensagens (a escolha do atendente, guardada em `users.sign_messages`). */
    public bool $sign = true;

    /** O arquivo que acabou de subir; `commitUpload` o move para a bandeja ou o descarta. */
    public ?TemporaryUploadedFile $upload = null;

    /** A gravação de voz que o navegador acabou de subir; `sendVoice` a envia. */
    public ?TemporaryUploadedFile $voice = null;

    /** @var array<int, TemporaryUploadedFile> Bandeja: arquivos aceitos, na ordem de envio. */
    public array $attachments = [];

    public function mountComposesMessages(): void
    {
        $this->sign = $this->user()->sign_messages;
    }

    public function toggleSign(): void
    {
        $this->sign = ! $this->sign;
        $this->user()->forceFill(['sign_messages' => $this->sign])->save();
    }

    /** O rascunho `/pre` chama as respostas rápidas do usuário; o texto entra no campo, editável. */
    public function useQuickReply(int $id): void
    {
        $this->authorize('reply', $this->selected());

        $this->draft = QuickReply::forUser($this->user())->findOrFail($id)->body;
    }

    /**
     * @return Collection<int, QuickReply>
     */
    public function quickReplies(): Collection
    {
        return QuickReply::forUser($this->user())->matching($this->draft)->get();
    }

    /** Entra na bandeja o arquivo que acabou de subir (um por requisição); o servidor decide se ele fica. */
    public function commitUpload(int $ticketId): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);
        $this->resetErrorBag('attachments');

        $file = $this->upload;
        $this->upload = null;

        if ($file === null) {
            return;
        }

        // O arquivo subiu enquanto o atendente estava em outra conversa: não entra na bandeja desta.
        if ($ticketId !== $ticket->id) {
            $file->delete();

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
        $this->validateDraft();

        $captioned = $this->captionsFirstFile();
        $separateText = $this->draft !== '' && ! $captioned;

        // Freio contra conta comprometida ou laço no navegador: cada mensagem enviada conta, arquivo inclusive.
        if (! $this->reserveSends(count($this->attachments) + ($separateText ? 1 : 0))) {
            $this->addError('draft', 'Você está enviando mensagens rápido demais. Aguarde alguns segundos.');

            return;
        }

        if ($separateText) {
            $text = Signature::apply($this->draft, $this->user(), $ticket->sector);
            SendTicketMessageJob::dispatch(app(SendTicketMessage::class)->create($ticket, $this->user(), $text)->id);
        }

        $failures = $this->sendAttachments($ticket, $captioned);

        $this->reset('draft', 'attachments');

        foreach ($failures as $failure) {
            $this->addError('attachments', $failure);
        }
    }

    /**
     * O atendente começou ou parou de gravar áudio: o cliente vê "gravando áudio…". Chega a cada poucos segundos
     * enquanto grava, então tem limite próprio (separado do de envio) e nunca devolve erro ao navegador.
     */
    public function recording(bool $on, int $ticketId): void
    {
        // O ticket vem do gravador (o da conversa em que ele nasceu), não da seleção atual: ao trocar de conversa, o aviso de
        // "parou" ainda precisa chegar ao cliente certo.
        $ticket = Ticket::query()->visibleTo($this->user())->findOrFail($ticketId);

        if (! $on && ! $this->user()->can('reply', $ticket)) {
            return;
        }

        $this->authorize('reply', $ticket);

        if ($on && ! RateLimiter::attempt('presence:'.$this->user()->id, self::PRESENCE_PER_MINUTE, fn () => true, 60)) {
            return;
        }

        $channel = $ticket->channel()->firstOrFail();
        $contact = $ticket->contact()->firstOrFail();

        if (! $channel->isConnected()) {
            return;
        }

        $presence = app(Presence::class);
        $presence->recording(new WahaClient($channel), $contact->wa_id ?? (string) $contact->lid, $on);

        if (! $on) {
            $presence->scheduleOffline($channel);
        }
    }

    /** Envia a gravação que o navegador acabou de subir (`voice`) como mensagem de voz do WhatsApp. */
    public function sendVoice(int $ticketId): void
    {
        $ticket = $this->selected();
        $this->authorize('reply', $ticket);
        $this->resetErrorBag('voice');

        $file = $this->voice;
        $this->voice = null;

        if ($file === null) {
            return;
        }

        // A gravação nasceu em outra conversa (o atendente trocou de ticket no meio): nunca vai para a que está na tela.
        if ($ticketId !== $ticket->id) {
            $file->delete();

            return;
        }

        $error = AttachmentRules::validate($file, true);

        if ($error === null && ! $this->reserveSends(1)) {
            $error = 'Você está enviando mensagens rápido demais. Aguarde alguns segundos.';
        }

        if ($error === null) {
            $error = $this->storeVoice($ticket, $file);
        }

        if ($error !== null) {
            $this->addError('voice', $error);
        }

        $file->delete();
    }

    private function storeVoice(Ticket $ticket, TemporaryUploadedFile $file): ?string
    {
        try {
            app(SendTicketMedia::class)($ticket, $this->user(), $file, '', true);
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function validateDraft(): void
    {
        // Sem arquivo o texto é obrigatório; com arquivo ele é só a legenda.
        $this->validate(
            ['draft' => [$this->attachments === [] ? 'required' : 'nullable', 'string', 'max:4096']],
            ['draft.required' => 'Escreva uma mensagem.', 'draft.max' => 'A mensagem passa de 4096 caracteres.'],
        );
    }

    /** O texto vira a legenda do primeiro arquivo? Não vira se o arquivo é áudio ou se o texto passa do limite de legenda. */
    private function captionsFirstFile(): bool
    {
        if ($this->attachments === [] || $this->draft === '' || mb_strlen($this->draft) > self::CAPTION_LIMIT) {
            return false;
        }

        $info = AttachmentRules::inspect($this->attachments[0]);

        return is_array($info) && $info['kind'] !== 'audio';
    }

    /**
     * @return list<string> mensagens de erro dos arquivos que o servidor recusou na hora de enviar
     */
    private function sendAttachments(Ticket $ticket, bool $captioned): array
    {
        $failures = [];

        foreach ($this->attachments as $position => $file) {
            try {
                app(SendTicketMedia::class)($ticket, $this->user(), $file, $captioned && $position === 0 ? $this->draft : '');
            } catch (InvalidArgumentException $e) {
                $failures[] = $e->getMessage();
            }

            $file->delete();
        }

        return $failures;
    }

    private function discardAttachments(): void
    {
        foreach ($this->attachments as $file) {
            $file->delete();
        }

        $this->reset('attachments', 'upload');
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
}
