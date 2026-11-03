<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\WahaEvent;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMessages;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Importação inicial: traz os últimos `backfill_days` dias (no máximo 30) como histórico. Cada chat vira um ticket
 * encerrado de importação, sem fila, SLA ou tempo real. O chat em que o cliente falou por último, sem resposta, nas
 * últimas 48 h entra pelo caminho normal (ticket aberto em `aguardando`), para ninguém que esperava sumir no histórico.
 */
class ImportHistory
{
    private const MAX_DAYS = 30;

    private const OPEN_WINDOW_HOURS = 48;

    /** A janela inteira fica na memória do worker para agrupar por chat: passar disso para a leitura (com aviso). */
    protected const MAX_MESSAGES = 50000;

    /** Quantas conversas entraram abertas na última rodada (legível depois da chamada). */
    public int $open = 0;

    /** `false` quando a rodada parou no prazo: chamar de novo continua de onde parou (o que já entrou é pulado). */
    public bool $complete = false;

    /** `true` quando a leitura parou no teto de mensagens (legível depois da chamada). */
    public bool $truncated = false;

    public function __construct(private readonly RecoverMessages $recover) {}

    public function __invoke(Channel $channel, ?CarbonImmutable $deadline = null): RecoveryResult
    {
        $to = CarbonImmutable::now();
        $from = $to->subDays(min(max($channel->backfill_days, 0), self::MAX_DAYS));

        $messages = 0;
        $tickets = [];
        $openTickets = [];
        $this->open = 0;
        $this->complete = false;
        $this->truncated = false;

        foreach ($this->chats($channel, $from, $to) as $items) {
            if ($deadline !== null && CarbonImmutable::now()->gte($deadline)) {
                return $this->result($messages, $tickets, $openTickets);
            }

            $last = $items[count($items) - 1][1];
            $open = ! $last->fromMe && $last->sentAt->gte($to->subHours(self::OPEN_WINDOW_HOURS));
            $mode = $open ? IngestMode::RECOVERED : IngestMode::IMPORTED;

            foreach ($items as [$data]) {
                $message = $this->recover->ingest($channel, $data, $mode);

                if ($message?->wasRecentlyCreated === true) {
                    $messages++;
                    $tickets[$message->ticket_id] = true;
                    $open && $openTickets[$message->ticket_id] = true;
                }
            }
        }

        $this->complete = true;

        return $this->result($messages, $tickets, $openTickets);
    }

    /**
     * @param  array<int, true>  $tickets
     * @param  array<int, true>  $openTickets
     */
    private function result(int $messages, array $tickets, array $openTickets): RecoveryResult
    {
        $this->open = count($openTickets);

        return new RecoveryResult($messages, count($tickets));
    }

    /**
     * As mensagens da janela agrupadas por chat e em ordem de horário.
     *
     * @return array<string, list<array{0: array<string, mixed>, 1: IncomingMessage}>>
     */
    private function chats(Channel $channel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $chats = [];
        $read = 0;

        foreach ((new WahaMessages(new WahaClient($channel)))->between($from, $to) as $data) {
            if (++$read > static::MAX_MESSAGES) {
                $this->truncated = true;
                Log::warning('Importação limitada ao teto de mensagens', ['canal' => $channel->id, 'teto' => static::MAX_MESSAGES]);

                break;
            }

            $incoming = IncomingMessage::fromEvent(new WahaEvent(['waha_event_id' => 'import-scan', 'payload' => ['payload' => $data], 'received_at' => $to]));

            if ($incoming !== null) {
                $chats[$incoming->peer][] = [$data, $incoming];
            }
        }

        foreach ($chats as &$items) {
            usort($items, fn (array $a, array $b): int => $a[1]->sentAt <=> $b[1]->sentAt);
        }

        return $chats;
    }
}
