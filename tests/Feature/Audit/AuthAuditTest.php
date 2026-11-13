<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '10.20.30.40';

    private function login(string $email, string $password): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])->post('/login', ['email' => $email, 'password' => $password]);
    }

    private function user(bool $active = true): User
    {
        return User::factory()->create(['password' => Hash::make('senha-certa-123'), 'active' => $active]);
    }

    public function test_login_grava_quem_entrou_e_o_ip(): void
    {
        $user = $this->user();

        $this->login($user->email, 'senha-certa-123');

        $log = AuditLog::query()->sole();
        $this->assertSame('auth.login', $log->action);
        $this->assertSame($user->id, $log->actor_user_id);
        $this->assertStringContainsString($user->email, (string) $log->actor_label);
        $this->assertSame(self::IP, $log->ip);
    }

    public function test_senha_errada_grava_a_falha_com_o_e_mail_e_sem_a_senha(): void
    {
        $user = $this->user();

        $this->login($user->email, 'senha-digitada-errada');

        $log = AuditLog::query()->sole();
        $this->assertSame('auth.login_failed', $log->action);
        $this->assertSame($user->email, $log->subject);
        $this->assertNull($log->actor_user_id);
        $this->assertSame(self::IP, $log->ip);
        $this->assertStringNotContainsString('senha-digitada-errada', (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_e_mail_inexistente_ou_gigante_nao_quebra(): void
    {
        $unknown = 'naoexiste-'.bin2hex(random_bytes(6)).'@dimetal.test';

        $this->login($unknown, 'qualquer-coisa');
        $this->login(str_repeat('a', 400).'@dimetal.test', 'qualquer-coisa');

        $this->assertSame(2, AuditLog::query()->where('action', 'auth.login_failed')->count());
        $this->assertSame($unknown, AuditLog::query()->orderBy('id')->firstOrFail()->subject);
        // O de 400 caracteres não é um e-mail válido: vira o texto padrão, e o assunto nunca passa de 255.
        $this->assertLessThanOrEqual(255, mb_strlen((string) AuditLog::query()->orderByDesc('id')->firstOrFail()->subject));
    }

    public function test_senha_digitada_no_campo_de_e_mail_nao_e_gravada_nem_aparece(): void
    {
        $this->login('SenhaForte#123', 'qualquer-coisa');
        // O login com campo vazio nem chega à autenticação (a validação recusa antes): o evento é disparado direto.
        event(new Failed('web', null, ['email' => '', 'password' => 'qualquer-coisa']));
        event(new Failed('web', null, ['email' => ['x'], 'password' => 'qualquer-coisa']));

        $this->assertSame(['(valor que não é e-mail)', '(vazio)', '(vazio)'], AuditLog::query()->orderBy('id')->pluck('subject')->all());
        $this->assertStringNotContainsString('SenhaForte#123', (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_usuario_inativo_com_a_senha_certa_conta_como_falha(): void
    {
        $user = $this->user(active: false);

        $this->login($user->email, 'senha-certa-123');

        $this->assertSame(['auth.login_failed'], AuditLog::query()->pluck('action')->all());
    }

    public function test_logout_grava(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/logout');

        $log = AuditLog::query()->sole();
        $this->assertSame('auth.logout', $log->action);
        $this->assertSame($user->id, $log->actor_user_id);
    }
}
