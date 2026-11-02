<?php

namespace App\Services\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Responde sozinho a quem escreve fora do horário de funcionamento, uma vez por ticket.
 *
 * Só reage a mensagem do cliente recebida agora (o histórico reprocessado e o que sai do celular ficam de fora), com o
 * ticket aberto e sem nenhum atendente tendo respondido. A marca "já respondi" é gravada sob o lock do ticket, na mesma
 * transação da mensagem: duas mensagens simultâneas do cliente geram uma resposta só.
 */
class AfterHoursReply
{
    public const DEFAULT_MESSAGE = 'Olá! Recebemos sua mensagem. Nosso atendimento funciona {{horario}}. Assim que estivermos de volta, um de nossos atendentes responde você. Voltamos {{proximo_horario}}.';

    /** Mensagem mais velha que isto é histórico reprocessado, não cliente esperando. */
    private const FRESH_SECONDS = 300;

    public function __construct(
        private readonly BusinessHours $hours,
        private readonly SendTicketMessage $send,
    ) {}

    public function __invoke(Message $incoming): ?Message
    {
        if (! $this->applies($incoming)) {
            return null;
        }

        $reply = DB::transaction(function () use ($incoming): ?Message {
            $ticket = Ticket::query()->lockForUpdate()->find($incoming->ticket_id);

            if ($ticket === null || ! $this->eligible($ticket)) {
                return null;
            }

            TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'after_hours_reply']);

            return $this->send->create($ticket, null, $this->text($incoming), MessageOrigin::BOT);
        });

        if ($reply !== null) {
            SendTicketMessageJob::dispatch($reply->id);
        }

        return $reply;
    }

    private function applies(Message $incoming): bool
    {
        return $incoming->direction === MessageDirection::IN
            && $incoming->origin === MessageOrigin::CONTATO
            && (bool) Setting::get('after_hours_enabled', true)
            // Canal com workflow: o fluxo decide o que dizer fora do horário (bloco Condição); duas respostas seriam ruído.
            && Channel::query()->whereKey($incoming->channel_id)->whereNull('workflow_id')->exists()
            && abs(now()->diffInSeconds($incoming->sent_at)) <= self::FRESH_SECONDS
            && ! $this->hours->isOpen($incoming->sent_at);
    }

    private function eligible(Ticket $ticket): bool
    {
        return $ticket->status !== TicketStatus::ENCERRADO
            && $ticket->first_human_reply_at === null
            && ! $ticket->events()->where('type', 'after_hours_reply')->exists();
    }

    private function text(Message $incoming): string
    {
        $template = Setting::get('after_hours_message');

        return $this->render(is_string($template) ? $template : null, $incoming->sent_at);
    }

    /** O texto com as variáveis preenchidas; o padrão vale quando o modelo está vazio. Usado também na prévia da tela. */
    public function render(?string $template, CarbonInterface $at): string
    {
        $template = $template !== null && trim($template) !== '' ? $template : self::DEFAULT_MESSAGE;

        return str_replace(
            ['{{horario}}', '{{proximo_horario}}'],
            [$this->hours->summary(), $this->hours->nextOpeningLabel($at)],
            $template,
        );
    }
}
