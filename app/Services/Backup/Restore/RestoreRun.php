<?php

namespace App\Services\Backup\Restore;

use Carbon\CarbonImmutable;

/** O estado de uma restauração em andamento, só para o orquestrador saber o que desfazer se algo falhar. */
final class RestoreRun
{
    /** O banco que vai assumir o nome ativo: o recém-restaurado (restore) ou o guardado (switch). */
    public string $incoming = '';

    public string $archive = '';

    /** O banco novo criado pelo `restore`, para apagar se falhar (nunca o guardado do `switch`). */
    public ?string $staging = null;

    public string $active = '';

    public string $label = '';

    /** O limite de tempo para trás da recuperação de mensagens: a data do backup ou a do banco guardado. */
    public ?CarbonImmutable $from = null;

    public ?string $path = null;

    public bool $inMaintenance = false;

    public bool $swapped = false;

    /** O ponto sem volta: o sistema já saiu da manutenção e grava no banco novo; falha depois daqui não desfaz a troca. */
    public bool $committed = false;

    /** O desfazer da troca falhou: o sistema fica em manutenção para ninguém gravar num banco meio migrado. */
    public bool $keepDown = false;

    /** @var list<string> avisos de passos de melhor esforço que falharam depois de a restauração já ter dado certo */
    public array $warnings = [];

    public readonly CarbonImmutable $startedAt;

    public function __construct(public readonly RestoreRequest $request)
    {
        $this->startedAt = CarbonImmutable::now();
    }
}
