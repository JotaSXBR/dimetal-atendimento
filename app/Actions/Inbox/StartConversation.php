<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Jobs\SendTicketMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\ConversationNotStarted;
use App\Services\Inbox\SendTicketMessage;
use App\Services\Inbox\Signature;
use App\Services\Inbox\SyncContactPhoto;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaContacts;
use App\Services\Waha\WahaRequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * O atendente começa uma conversa: a única porta em que o ticket nasce de uma mensagem nossa. Toda recusa acontece antes de
 * criar qualquer linha; o ticket e a primeira mensagem nascem juntos, o ticket já em atendimento e do autor, sem SLA e sem bot.
 */
class StartConversation
{
    public const TEXT_LIMIT = 4096;

    /** Segundos mínimos entre duas conversas iniciadas pelo app no mesmo canal, e o teto por contato em uma hora. */
    private const CHANNEL_GAP_SECONDS = 30;

    private const CONTACT_PER_HOUR = 4;

    public function __construct(private readonly SendTicketMessage $messages) {}

    /**
     * @return array{ticket: Ticket, created: bool} `created` falso quando já havia ticket aberto do contato no canal (nada é enviado)
     *
     * @throws ConversationNotStarted
     */
    public function __invoke(User $user, Contact $contact, Channel $channel, Sector $sector, string $text): array
    {
        $text = trim($text);
        $this->guard($user, $contact, $channel, $sector, $text);

        $open = $this->openTicket($contact, $channel);

        if ($open !== null) {
            return ['ticket' => $open, 'created' => false];
        }

        $this->assertHasWhatsApp((string) $contact->wa_id, $channel);
        $this->reservePace($contact, $channel);

        $made = DB::transaction(function () use ($user, $contact, $channel, $sector, $text): array {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

            $existing = $this->openTicket($contact, $channel);

            if ($existing !== null) {
                return ['ticket' => $existing, 'message' => null];
            }

            $ticket = Ticket::create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'sector_id' => $sector->id,
                'assigned_user_id' => $user->id,
                'status' => TicketStatus::EM_ATENDIMENTO,
                'opened_at' => now(),
            ]);

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'type' => 'opened_by_agent',
                'actor_user_id' => $user->id,
                'payload' => ['channel_id' => $channel->id, 'sector_id' => $sector->id],
            ]);

            return ['ticket' => $ticket, 'message' => $this->messages->create($ticket, $user, Signature::apply($text, $user, $sector))];
        });

        if ($made['message'] instanceof Message) {
            app(SyncContactPhoto::class)->queueFor($contact, $channel);
            SendTicketMessageJob::dispatch($made['message']->id);
            TicketUpdated::dispatch($made['ticket']);
        }

        return ['ticket' => $made['ticket'], 'created' => $made['message'] instanceof Message];
    }

    /** @throws ConversationNotStarted */
    private function guard(User $user, Contact $contact, Channel $channel, Sector $sector, string $text): void
    {
        $problem = match (true) {
            $text === '' => 'Escreva a primeira mensagem.',
            mb_strlen($text) > self::TEXT_LIMIT => 'A mensagem passa de '.self::TEXT_LIMIT.' caracteres.',
            ! $user->active => 'Seu usuário está inativo.',
            ! $channel->active || ! $channel->isConnected() => 'Este canal está desativado ou desconectado do WhatsApp.',
            ! str_ends_with((string) $contact->wa_id, '@c.us') => 'Este contato não tem telefone: só dá para conversar com quem tem número de WhatsApp.',
            ! $sector->status->receivesTickets() || ! $user->canHandleSector($sector->id) => 'Escolha um setor que receba tickets e que você atenda.',
            default => null,
        };

        if ($problem !== null) {
            throw new ConversationNotStarted($problem);
        }
    }

    private function openTicket(Contact $contact, Channel $channel): ?Ticket
    {
        return Ticket::query()->open()->where('contact_id', $contact->id)->where('channel_id', $channel->id)->first();
    }

    /**
     * Público para a janela conferir um telefone novo antes de cadastrar o contato (número sem WhatsApp não vira contato).
     *
     * @throws ConversationNotStarted
     */
    public function assertHasWhatsApp(string $waId, Channel $channel): void
    {
        try {
            $exists = (new WahaContacts(new WahaClient($channel)))->numberExists($waId);
        } catch (WahaRequestException) {
            throw new ConversationNotStarted('Não foi possível confirmar o número agora. Tente de novo em instantes.');
        }

        if (! $exists) {
            throw new ConversationNotStarted('Este número não tem WhatsApp.');
        }
    }

    /** @throws ConversationNotStarted */
    private function reservePace(Contact $contact, Channel $channel): void
    {
        $channelKey = 'start-conversation:channel:'.$channel->id;
        $contactKey = 'start-conversation:contact:'.$contact->id;

        foreach ([[$channelKey, 1], [$contactKey, self::CONTACT_PER_HOUR]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ConversationNotStarted('Aguarde '.RateLimiter::availableIn($key).' s para iniciar outra conversa '.($key === $channelKey ? 'neste canal.' : 'com este contato.'));
            }
        }

        RateLimiter::hit($channelKey, self::CHANNEL_GAP_SECONDS);
        RateLimiter::hit($contactKey, 3600);
    }
}
