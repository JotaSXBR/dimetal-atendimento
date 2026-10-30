<?php

namespace App\Services\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Settings\CompanySettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * O relógio de SLA: a rotina `app:check-sla` chama isto a cada minuto. Quatro passos, cada um limitado a {@see self::BATCH}
 * tickets por rodada (o resto sai na seguinte), todos sobre tickets abertos e lidos pelos índices parciais:
 *
 * 1. adotar: quem já esperava antes da implantação ganha `waiting_since` (o instante da última mensagem do cliente);
 * 2. calcular: o aviso (75% do limite) e o vencimento (100%) em segundos de expediente, uma vez por espera;
 * 3. avisar e 4. estourar: gravam a marca e avisam a tela; o estouro também cria o evento `sla_breached`.
 *
 * A rodada lê e depois grava, e leva segundos: uma resposta humana ou uma mensagem do cliente pode chegar no meio. Por isso
 * cada UPDATE repete o predicado inteiro da seleção (não só a marca) e é atômico: o que mudou no meio não é gravado, e duas
 * rodadas ao mesmo tempo não repetem aviso nem evento. Os instantes vêm do `now()` do PHP, nunca do SQL: a coluna é
 * `timestamp` sem fuso e o relógio dos testes só vale no PHP.
 */
final class SlaClock
{
    public const BATCH = 200;

    private const WARN_RATIO = 0.75;

    public function __construct(private readonly BusinessHours $hours) {}

    /**
     * @return array{adopted: int, scheduled: int, warned: int, breached: int}
     */
    public function __invoke(): array
    {
        $now = now();

        return [
            'adopted' => $this->adopt(),
            'scheduled' => $this->schedule(),
            'warned' => $this->mark($this->due('sla_warn_at', 'sla_warned_at', $now), 'sla_warned_at', $now, false),
            'breached' => $this->mark($this->due('sla_due_at', 'sla_breached_at', $now), 'sla_breached_at', $now, true),
        ];
    }

    /**
     * Os dois filtros de status são de propósito: `status <> 'encerrado'` é o predicado dos índices parciais e o `in` diz quais
     * estados contam.
     *
     * @return Builder<Ticket>
     */
    private function open(): Builder
    {
        return Ticket::query()
            ->where('status', '<>', TicketStatus::ENCERRADO->value)
            ->whereIn('status', [TicketStatus::AGUARDANDO->value, TicketStatus::EM_ATENDIMENTO->value]);
    }

    /**
     * Aberto, com espera em curso e o prazo (`$deadline`) já passado sem a marca (`$stamp`). Vale para ler e para gravar.
     *
     * @return Builder<Ticket>
     */
    private function due(string $deadline, string $stamp, CarbonInterface $now): Builder
    {
        return $this->open()->whereNotNull('waiting_since')->whereNull($stamp)->where($deadline, '<=', $now);
    }

    /**
     * Aberto, sem espera registrada, com mensagem do cliente ainda sem resposta. Vale para ler e para gravar.
     *
     * @return Builder<Ticket>
     */
    private function unregisteredWait(): Builder
    {
        return $this->open()
            ->whereNull('waiting_since')
            ->whereNotNull('last_inbound_at')
            ->where(fn (Builder $q) => $q->whereNull('last_outbound_at')->orWhereColumn('last_inbound_at', '>', 'last_outbound_at'));
    }

    private function adopt(): int
    {
        $ids = $this->unregisteredWait()->orderBy('id')->limit(self::BATCH)->get(['id'])->modelKeys();

        return $ids === [] ? 0 : $this->unregisteredWait()->whereKey($ids)->update(['waiting_since' => DB::raw('last_inbound_at')]);
    }

    /**
     * shortcut: o vencimento é calculado uma vez por espera, com o limite e o horário de funcionamento de agora; mudar um dos
     * dois vale só para esperas novas. Subir quando houver pedido de recalcular o que já está esperando.
     */
    private function schedule(): int
    {
        $seconds = CompanySettings::slaMinutes() * 60;
        $scheduled = 0;

        $tickets = $this->open()
            ->whereNotNull('waiting_since')
            ->whereNull('sla_due_at')
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get(['id', 'waiting_since']);

        foreach ($tickets as $ticket) {
            $since = $ticket->waiting_since;

            if ($since === null) {
                continue;
            }

            // A espera tem de ser a mesma de quando o vencimento foi calculado: se acabou ou recomeçou no meio, não grava.
            $scheduled += $this->open()->whereKey($ticket->id)->where('waiting_since', $since)->whereNull('sla_due_at')->update([
                'sla_warn_at' => $this->hours->addBusinessSeconds($since, (int) round($seconds * self::WARN_RATIO)),
                'sla_due_at' => $this->hours->addBusinessSeconds($since, $seconds),
            ]);
        }

        return $scheduled;
    }

    /**
     * Grava a marca de cada ticket que a seleção devolve, de forma atômica, e avisa a tela. O estouro também cria o evento.
     *
     * @param  Builder<Ticket>  $selection  o predicado, aplicado de novo no UPDATE de cada ticket
     */
    private function mark(Builder $selection, string $stamp, CarbonInterface $now, bool $event): int
    {
        $marked = 0;

        foreach ((clone $selection)->orderBy('id')->limit(self::BATCH)->get() as $ticket) {
            $won = DB::transaction(function () use ($selection, $ticket, $stamp, $now, $event): bool {
                if ((clone $selection)->whereKey($ticket->id)->update([$stamp => $now]) !== 1) {
                    return false;
                }

                if ($event) {
                    TicketEvent::create([
                        'ticket_id' => $ticket->id,
                        'type' => 'sla_breached',
                        'payload' => [
                            'waiting_since' => $ticket->waiting_since?->utc()->toIso8601String(),
                            'due_at' => $ticket->sla_due_at?->utc()->toIso8601String(),
                        ],
                    ]);
                }

                return true;
            });

            if ($won) {
                TicketUpdated::dispatch($ticket);
                $marked++;
            }
        }

        return $marked;
    }
}
