<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Compositor: em coluna larga tudo numa linha; em coluna estreita as abas descem para a linha de cima (spec 4a). */
class InboxComposerLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function xpath(User $user, TicketStatus $status, ?int $assignee = null): \DOMXPath
    {
        $ticket = Ticket::factory()->create([
            'channel_id' => (Channel::query()->first() ?? Channel::factory()->connected()->create())->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => $status,
            'assigned_user_id' => $assignee,
            'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null,
        ]);

        $html = Livewire::actingAs($user)->test(Inbox::class)->call('select', $ticket->id)->html();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_abas_de_modo_ficam_dentro_do_formulario_do_compositor(): void
    {
        $admin = $this->admin();
        $xpath = $this->xpath($admin, TicketStatus::EM_ATENDIMENTO, $admin->id);

        $form = $xpath->query('//form[@data-testid="composer-form"]')->item(0);

        $this->assertNotNull($form);
        $this->assertStringContainsString('flex-wrap', $form->getAttribute('class'));
        $this->assertSame(1, $xpath->query('.//*[@data-testid="mode-reply"]', $form)->length);
        $this->assertSame(1, $xpath->query('.//*[@data-testid="mode-note"]', $form)->length);
    }

    public function test_abas_ocupam_linha_propria_em_coluna_estreita(): void
    {
        $admin = $this->admin();
        $modes = $this->xpath($admin, TicketStatus::EM_ATENDIMENTO, $admin->id)->query('//*[@data-testid="composer-modes"]')->item(0);

        $this->assertNotNull($modes);
        $this->assertStringContainsString('@max-xl:basis-full', $modes->getAttribute('class'));
    }

    public function test_ticket_encerrado_nao_tem_compositor(): void
    {
        $xpath = $this->xpath($this->admin(), TicketStatus::ENCERRADO);

        $this->assertSame(0, $xpath->query('//*[@data-testid="composer-form"]')->length);
        $this->assertStringContainsString('Ticket encerrado. Somente leitura.', $xpath->query('//footer')->item(0)?->textContent ?? '');
    }

    public function test_usuario_sem_permissao_de_responder_nao_ve_compositor(): void
    {
        // Ticket só aguardando: ninguém o assumiu, então ninguém responde ainda.
        $xpath = $this->xpath($this->admin(), TicketStatus::AGUARDANDO);

        $this->assertSame(0, $xpath->query('//*[@data-testid="composer-form"]')->length);
        $this->assertStringContainsString('Assuma o ticket para responder.', $xpath->query('//footer')->item(0)?->textContent ?? '');
    }
}
