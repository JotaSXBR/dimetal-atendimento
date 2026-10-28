<?php

namespace App\Services\Inbox;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Protocolo `AAAA-NNNNNN`, sequencial por ano. Chamar dentro da transação que cria o ticket: o incremento fica
 * preso à linha do ano até o commit, então duas criações simultâneas não repetem número e, se o ticket não for
 * gravado, o número volta (a sequência não tem buracos).
 */
final class TicketProtocol
{
    public static function next(CarbonInterface $openedAt): string
    {
        // O ano é o de São Paulo: um ticket aberto às 22h de 31/12 já é 01/01 em UTC.
        $year = $openedAt->copy()->timezone(config()->string('app.display_timezone'))->year;

        $last = DB::scalar(
            'insert into ticket_sequences (year, last) values (?, 1)
             on conflict (year) do update set last = ticket_sequences.last + 1
             returning last',
            [$year],
        );

        return sprintf('%d-%06d', $year, is_numeric($last) ? (int) $last : throw new LogicException('Sequência do protocolo sem retorno.'));
    }
}
