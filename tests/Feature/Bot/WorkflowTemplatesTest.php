<?php

namespace Tests\Feature\Bot;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Bot\BotEngine;
use App\Services\Bot\WorkflowPublisher;
use App\Services\Bot\WorkflowTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorkflowTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Sector $vendas;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->user = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $this->vendas = Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        Sector::factory()->create(['name' => 'Suporte', 'menu_order' => 2]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create(['name' => 'Maria'])->id,
            'status' => TicketStatus::PENDENTE,
        ]);
    }

    /** @return list<string> */
    private function botTexts(): array
    {
        return Message::query()->where('ticket_id', $this->ticket->id)->where('origin', 'bot')->orderBy('id')->pluck('body')->all();
    }

    public function test_a_lista_traz_os_tres_modelos_na_ordem(): void
    {
        $all = WorkflowTemplates::all();

        $this->assertSame(['em_branco', 'padrao', 'horario'], array_column($all, 'key'));
        $this->assertSame(['Em branco', 'Atendimento padrão', 'Atendimento com horário'], array_column($all, 'name'));

        foreach ($all as $template) {
            $this->assertNotSame('', $template['description']);
        }
    }

    public function test_cada_modelo_cria_rascunho_valido_sem_publicar(): void
    {
        $publisher = app(WorkflowPublisher::class);

        foreach (WorkflowTemplates::all() as $template) {
            $workflow = $publisher->create('Fluxo '.$template['key'], $this->user, $template['key']);

            $this->assertSame(WorkflowTemplates::graph($template['key']), $workflow->draft);
            $this->assertSame(0, $workflow->versions()->count());
            $this->assertSame(0, Channel::query()->whereNotNull('workflow_id')->count());

            if ($template['key'] !== WorkflowTemplates::BLANK) {
                $this->assertSame([], $publisher->check($workflow), $template['key']);
            }
        }

        $this->assertCount(8, WorkflowTemplates::graph(WorkflowTemplates::WITH_HOURS)['nodes']);
    }

    public function test_chave_desconhecida_cria_em_branco(): void
    {
        $workflow = app(WorkflowPublisher::class)->create('Qualquer', $this->user, 'magia');

        $this->assertCount(1, $workflow->draft['nodes']);
    }

    public function test_modelo_com_horario_dentro_do_horario_passa_pelo_menu(): void
    {
        BusinessHour::create(['weekday' => 1, 'opens_at' => '08:00:00', 'closes_at' => '18:00:00']);
        $this->travelTo(Carbon::parse('2026-11-09 10:00:00', config()->string('app.display_timezone')));
        $publisher = app(WorkflowPublisher::class);
        $workflow = $publisher->create('Com horário', $this->user, WorkflowTemplates::WITH_HOURS);

        app(BotEngine::class)->start($this->ticket, $publisher->publish($workflow, $this->user));

        $texts = $this->botTexts();
        $this->assertStringContainsString('Bem-vindo a ', $texts[0]);
        $this->assertStringContainsString('1 - Vendas', $texts[1]);
        $this->assertSame(TicketStatus::PENDENTE, $this->ticket->fresh()?->status);
    }

    public function test_modelo_com_horario_fora_do_horario_avisa_e_vai_a_fila_padrao(): void
    {
        BusinessHour::create(['weekday' => 1, 'opens_at' => '08:00:00', 'closes_at' => '18:00:00']);
        $this->travelTo(Carbon::parse('2026-11-09 22:00:00', config()->string('app.display_timezone')));
        $publisher = app(WorkflowPublisher::class);
        $workflow = $publisher->create('Com horário', $this->user, WorkflowTemplates::WITH_HOURS);

        app(BotEngine::class)->start($this->ticket, $publisher->publish($workflow, $this->user));

        $this->assertStringContainsString('segunda, das 8h às 18h', $this->botTexts()[0]);
        $fresh = $this->ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertSame($this->vendas->id, $fresh?->sector_id);
    }
}
