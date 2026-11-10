<?php

namespace Tests\Unit;

use App\Enums\ChannelStatus;
use App\Enums\CloseOutcome;
use App\Enums\GoogleConnectionStatus;
use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** O tom de cada estado vive no enum (fonte única) e o `x-status-badge` só o mostra. */
class EnumToneTest extends TestCase
{
    private const TONES = ['neutral', 'bot', 'ok', 'warn', 'danger', 'accent'];

    /** @return array<string, array{0: \BackedEnum, 1: string}> */
    public static function tabela(): array
    {
        return [
            'ticket pendente' => [TicketStatus::PENDENTE, 'bot'],
            'ticket aguardando' => [TicketStatus::AGUARDANDO, 'warn'],
            'ticket em atendimento' => [TicketStatus::EM_ATENDIMENTO, 'ok'],
            'ticket encerrado' => [TicketStatus::ENCERRADO, 'neutral'],
            'canal conectado' => [ChannelStatus::WORKING, 'ok'],
            'canal falhou' => [ChannelStatus::FAILED, 'danger'],
            'canal aguardando QR' => [ChannelStatus::SCAN_QR_CODE, 'warn'],
            'canal pede senha' => [ChannelStatus::PASSKEY_REQUIRED, 'warn'],
            'canal confirma senha' => [ChannelStatus::PASSKEY_CONFIRMATION_REQUIRED, 'warn'],
            'canal parado' => [ChannelStatus::STOPPED, 'neutral'],
            'canal iniciando' => [ChannelStatus::STARTING, 'neutral'],
            'setor ativo' => [SectorStatus::ATIVO, 'ok'],
            'setor fora do menu' => [SectorStatus::FORA_DO_MENU, 'bot'],
            'setor desativado' => [SectorStatus::DESATIVADO, 'neutral'],
            'google conectado' => [GoogleConnectionStatus::CONNECTED, 'ok'],
            'google precisa reautenticar' => [GoogleConnectionStatus::NEEDS_REAUTH, 'warn'],
            'desfecho resolvido' => [CloseOutcome::RESOLVIDO, 'ok'],
            'desfecho sem resposta' => [CloseOutcome::SEM_RESPOSTA, 'neutral'],
            'desfecho cliente pediu' => [CloseOutcome::CLIENTE_PEDIU, 'neutral'],
        ];
    }

    #[DataProvider('tabela')]
    public function test_tom_de_cada_estado(\BackedEnum $case, string $tone): void
    {
        $this->assertSame($tone, $case->tone());
    }

    /** Estado novo sem tom quebra aqui (o `match` do enum não tem `default`). */
    public function test_todo_caso_dos_enums_tem_tom_valido(): void
    {
        foreach ([TicketStatus::class, ChannelStatus::class, SectorStatus::class, GoogleConnectionStatus::class, CloseOutcome::class] as $enum) {
            foreach ($enum::cases() as $case) {
                $this->assertContains($case->tone(), self::TONES, $enum.'::'.$case->name);
            }
        }
    }
}
