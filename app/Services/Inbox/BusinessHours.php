<?php

namespace App\Services\Inbox;

use App\Models\BusinessHour;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;

/**
 * Horário de funcionamento: "está aberto agora?", "quando abre de novo?" e o texto do horário para mensagens.
 *
 * Sem nenhuma faixa cadastrada o atendimento conta como sempre aberto (falha segura: nada é respondido nem marcado).
 *
 * shortcut: a faixa vale só dentro do mesmo dia (não cruza a meia-noite); subir quando houver turno noturno.
 */
class BusinessHours
{
    /** Ordem de exibição: segunda a domingo. */
    private const DISPLAY_ORDER = [1, 2, 3, 4, 5, 6, 0];

    private const NAMES = [0 => 'domingo', 1 => 'segunda', 2 => 'terça', 3 => 'quarta', 4 => 'quinta', 5 => 'sexta', 6 => 'sábado'];

    private const LONG_NAMES = [0 => 'domingo', 1 => 'segunda-feira', 2 => 'terça-feira', 3 => 'quarta-feira', 4 => 'quinta-feira', 5 => 'sexta-feira', 6 => 'sábado'];

    /** Quantos dias à frente procurar a próxima abertura (cobre feriados emendados). */
    private const LOOKAHEAD_DAYS = 60;

    public function isOpen(CarbonInterface $at): bool
    {
        $windows = $this->windows();

        if ($windows === []) {
            return true;
        }

        $local = $this->local($at);

        if (in_array($local->toDateString(), $this->holidays(), true)) {
            return false;
        }

        $time = $local->format('H:i:s');

        foreach ($windows[$local->dayOfWeek] ?? [] as $window) {
            if ($window->opens_at <= $time && $time < $window->closes_at) {
                return true;
            }
        }

        return false;
    }

    public function nextOpening(CarbonInterface $at): ?CarbonImmutable
    {
        $windows = $this->windows();

        if ($windows === []) {
            return null;
        }

        $local = $this->local($at);
        $holidays = $this->holidays();

        for ($d = 0; $d <= self::LOOKAHEAD_DAYS; $d++) {
            $day = $local->startOfDay()->addDays($d);

            if (in_array($day->toDateString(), $holidays, true)) {
                continue;
            }

            foreach ($windows[$day->dayOfWeek] ?? [] as $window) {
                $opening = $day->setTimeFromTimeString($window->opens_at);

                if ($opening->greaterThan($local)) {
                    return $opening;
                }
            }
        }

        return null;
    }

    /** "hoje às 13h", "amanhã às 8h", "segunda-feira às 8h30"; vazio quando não há faixa. */
    public function nextOpeningLabel(CarbonInterface $at): string
    {
        $next = $this->nextOpening($at);

        return $next === null ? '' : $this->whenLabel($next, $at);
    }

    /** O instante `$at` como "hoje às 10h20", "amanhã às 8h10" ou "segunda-feira às 8h30", visto de `$now`; no fuso de exibição. */
    public function whenLabel(CarbonInterface $at, CarbonInterface $now): string
    {
        $local = $this->local($at);
        $days = (int) $this->local($now)->startOfDay()->diffInDays($local->startOfDay());
        $when = match (true) {
            $days === 0 => 'hoje',
            $days === 1 => 'amanhã',
            default => self::LONG_NAMES[$local->dayOfWeek],
        };

        return $when.' às '.$this->hour($local->format('H:i:s'));
    }

    /**
     * O instante em que se completam `$seconds` segundos de expediente a partir de `$from`. Fora do expediente (fechado, feriado)
     * a conta começa na próxima abertura; esgotar uma faixa exata vence no fechamento dela. Sem nenhuma faixa cadastrada o
     * atendimento conta como sempre aberto e os segundos são somados direto. Devolve no fuso do banco.
     *
     * shortcut: passados os 60 dias de busca devolve o fim da última faixa vista, sem somar o resto; subir se um calendário
     * ficar tão vazio (um erro de cadastro) a ponto de importar.
     */
    public function addBusinessSeconds(CarbonInterface $from, int $seconds): CarbonImmutable
    {
        $windows = $this->windows();
        $cursor = $this->local($from);
        $databaseTimezone = config()->string('app.timezone');

        if ($windows === []) {
            return $cursor->addSeconds($seconds)->setTimezone($databaseTimezone);
        }

        $holidays = $this->holidays();
        $left = $seconds;
        $reached = $cursor;

        for ($d = 0; $d <= self::LOOKAHEAD_DAYS; $d++) {
            $day = $cursor->startOfDay()->addDays($d);

            if (in_array($day->toDateString(), $holidays, true)) {
                continue;
            }

            foreach ($windows[$day->dayOfWeek] ?? [] as $window) {
                $begin = $day->setTimeFromTimeString($window->opens_at)->max($cursor);
                $end = $day->setTimeFromTimeString($window->closes_at);
                $available = $end->getTimestamp() - $begin->getTimestamp();

                if ($available <= 0) {
                    continue;
                }

                if ($left <= $available) {
                    return $begin->addSeconds($left)->setTimezone($databaseTimezone);
                }

                $left -= $available;
                $reached = $end;
            }
        }

        return $reached->setTimezone($databaseTimezone);
    }

    /** "de segunda a sexta, das 8h às 12h e das 13h às 18h; sábado, das 8h às 12h". */
    public function summary(): string
    {
        $windows = $this->windows();
        $runs = [];
        $previousHadWindows = false;

        // Dias vizinhos com as mesmas faixas viram um trecho; um dia sem faixa interrompe o trecho.
        foreach (self::DISPLAY_ORDER as $weekday) {
            $list = $windows[$weekday] ?? [];

            if ($list === []) {
                $previousHadWindows = false;

                continue;
            }

            $signature = implode(' e ', array_map(
                fn (BusinessHour $w): string => 'das '.$this->hour($w->opens_at).' às '.$this->hour($w->closes_at),
                $list,
            ));
            $last = array_key_last($runs);

            if ($previousHadWindows && $last !== null && $runs[$last]['signature'] === $signature) {
                $runs[$last]['days'][] = $weekday;
            } else {
                $runs[] = ['signature' => $signature, 'days' => [$weekday]];
            }

            $previousHadWindows = true;
        }

        return implode('; ', array_map(fn (array $run): string => $this->days($run['days']).', '.$run['signature'], $runs));
    }

    /** @return array<int, list<BusinessHour>> faixas por dia da semana, em ordem de abertura */
    private function windows(): array
    {
        $byDay = [];

        foreach (BusinessHour::query()->orderBy('opens_at')->get() as $window) {
            $byDay[$window->weekday][] = $window;
        }

        return $byDay;
    }

    /** @return list<string> datas `Y-m-d` */
    private function holidays(): array
    {
        return array_values(array_map(fn (Holiday $h): string => $h->date->toDateString(), Holiday::query()->get()->all()));
    }

    private function local(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config()->string('app.display_timezone'));
    }

    private function hour(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $m === 0 ? $h.'h' : sprintf('%dh%02d', $h, $m);
    }

    /** @param  list<int>  $days */
    private function days(array $days): string
    {
        $names = array_map(fn (int $d): string => self::NAMES[$d], $days);

        return match (count($names)) {
            1, 2 => implode(' e ', $names),
            default => 'de '.Arr::first($names).' a '.Arr::last($names),
        };
    }
}
