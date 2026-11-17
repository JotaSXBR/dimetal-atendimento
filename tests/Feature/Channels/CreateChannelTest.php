<?php

namespace Tests\Feature\Channels;

use App\Actions\Channels\CreateChannel;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_canal_e_audita(): void
    {
        $user = User::factory()->create();

        $channel = (new CreateChannel)('vendas_1', $user);

        $this->assertSame('vendas_1', $channel->name);
        $this->assertSame('vendas_1', $channel->waha_session);
        $this->assertDatabaseHas('channels', ['waha_session' => 'vendas_1']);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::ChannelCreated->value)->count());
    }

    public function test_sessao_repetida_lanca_validacao_no_campo_session_name(): void
    {
        Channel::factory()->create(['waha_session' => 'vendas']);

        try {
            (new CreateChannel)('vendas', null);
            $this->fail('Esperava ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['sessionName' => ['Já existe um canal com essa sessão.']], $e->errors());
        }
    }
}
