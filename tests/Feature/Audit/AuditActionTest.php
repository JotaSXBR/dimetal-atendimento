<?php

namespace Tests\Feature\Audit;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Support\AuditLogText;
use Tests\TestCase;

class AuditActionTest extends TestCase
{
    public function test_todo_caso_tem_rotulo_e_grupo_conhecido(): void
    {
        $this->assertCount(20, AuditAction::cases());
        $this->assertSame(['Acesso', 'Usuários', 'Backup', 'Configurações'], AuditAction::groups());

        foreach (AuditAction::cases() as $action) {
            $this->assertNotSame('', $action->label(), $action->value);
            $this->assertContains($action->group(), AuditAction::groups(), $action->value);
        }
    }

    public function test_valores_gravados_e_grupos(): void
    {
        $this->assertSame('auth.login_failed', AuditAction::LoginFailed->value);
        $this->assertSame('Acesso', AuditAction::Logout->group());
        $this->assertSame('Usuários', AuditAction::UserRoleChanged->group());
        $this->assertSame('Backup', AuditAction::RestoreFailed->group());
        $this->assertSame('Configurações', AuditAction::RetentionPurgeRequested->group());
    }

    public function test_toda_acao_vira_frase_em_portugues_sem_json_nem_segredo(): void
    {
        foreach (AuditAction::cases() as $action) {
            $log = new AuditLog([
                'action' => $action->value,
                'actor_label' => 'Ana Souza <ana@dimetal.test>',
                'subject' => 'Alvo',
                'detail' => ['role' => 'atendente', 'from' => 'atendente', 'to' => 'supervisor', 'fields' => ['name', 'sectors'], 'mode' => 'restore', 'message' => 'Concluída.', 'password' => 'senha-secreta-123', 'token' => 'tok-secreto'],
            ]);

            $sentence = AuditLogText::sentence($log);

            $this->assertNotSame('', $sentence, $action->value);
            $this->assertStringNotContainsString('{', $sentence, $action->value);
            $this->assertStringNotContainsString('senha-secreta-123', $sentence, $action->value);
            $this->assertStringNotContainsString('tok-secreto', $sentence, $action->value);
        }
    }

    public function test_frases_conhecidas(): void
    {
        $text = fn (AuditAction $a, ?string $subject, array $detail = [], ?string $actor = 'Ana Souza <ana@dimetal.test>'): string => AuditLogText::sentence(new AuditLog(['action' => $a->value, 'actor_label' => $actor, 'subject' => $subject, 'detail' => $detail]));

        $this->assertSame('Ana Souza <ana@dimetal.test> entrou no sistema', $text(AuditAction::Login, null));
        $this->assertSame('Login recusado para ana@dimetal.test', $text(AuditAction::LoginFailed, 'ana@dimetal.test', [], null));
        $this->assertSame('Ana Souza <ana@dimetal.test> mudou o papel de Beto: '.UserRole::ATENDENTE->label().' → '.UserRole::SUPERVISOR->label(), $text(AuditAction::UserRoleChanged, 'Beto', ['from' => 'atendente', 'to' => 'supervisor']));
        $this->assertSame('Ana Souza <ana@dimetal.test> alterou o usuário Beto (nome, setores)', $text(AuditAction::UserUpdated, 'Beto', ['fields' => ['name', 'sectors']]));
        $this->assertSame('Ana Souza <ana@dimetal.test> criou o usuário Beto ('.UserRole::ATENDENTE->label().')', $text(AuditAction::UserCreated, 'Beto', ['role' => 'atendente']));
    }

    public function test_acao_desconhecida_aparece_pelo_proprio_nome(): void
    {
        $this->assertSame('algo.novo', AuditLogText::sentence(new AuditLog(['action' => 'algo.novo', 'detail' => ['x' => 1]])));
    }
}
