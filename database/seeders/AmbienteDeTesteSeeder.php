<?php

namespace Database\Seeders;

use Database\Seeders\Cenarios\AlertasDoSino;
use Database\Seeders\Cenarios\BolhasDeTamanhosVariados;
use Database\Seeders\Cenarios\CanalConectado;
use Database\Seeders\Cenarios\ConversaDaAutomacao;
use Database\Seeders\Cenarios\EtiquetasDosDoisEscopos;
use Database\Seeders\Cenarios\NomesLongos;
use Database\Seeders\Cenarios\NotaInterna;
use Database\Seeders\Cenarios\TicketsComSla;
use Database\Seeders\Cenarios\TicketsEmTodosOsEstados;
use Database\Seeders\Cenarios\UsuariosDeTeste;
use Illuminate\Database\Seeder;

/** Dados para a conferência visual: cada cenário é uma classe em `Cenarios/`. Só roda em `local` e `testing`. */
class AmbienteDeTesteSeeder extends Seeder
{
    use SoRodaEmAmbienteDeTeste;

    public const ADMIN_EMAIL = 'dev@teste.local';

    public const ATENDENTE_EMAIL = 'atendente@teste.local';

    public const SENHA = 'secret123';

    public function run(): void
    {
        $this->exigirAmbienteDeTeste();

        $this->call([
            SectorSeeder::class,
            TagSeeder::class,
            DefaultWorkflowSeeder::class,
            UsuariosDeTeste::class,
            CanalConectado::class,
            TicketsEmTodosOsEstados::class,
            NomesLongos::class,
            BolhasDeTamanhosVariados::class,
            EtiquetasDosDoisEscopos::class,
            NotaInterna::class,
            ConversaDaAutomacao::class,
            AlertasDoSino::class,
            TicketsComSla::class,
        ]);
    }
}
