<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A linha de baixo do cabeçalho da conversa: protocolo, telefone, canal, setor e responsável (o bloco "Ticket" saiu do painel). */
class InboxHeaderLineTest extends TestCase
{
    use RefreshDatabase;

    private function line(Ticket $ticket): string
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $html = Livewire::actingAs($admin)->test(Inbox::class)->call('select', $ticket->id)->html();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $node = (new \DOMXPath($dom))->query('//*[@data-testid="header-line"]')->item(0);

        $this->assertNotNull($node, 'linha do cabeçalho ausente');

        // `data-line` repete a linha inteira (o texto visível leva também o tooltip do protocolo).
        $this->assertInstanceOf(\DOMElement::class, $node);

        return (string) preg_replace('/\s+/', ' ', trim($node->getAttribute('data-line')));
    }

    public function test_linha_traz_protocolo_telefone_canal_setor_e_responsavel(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);
        $channel = Channel::factory()->create(['name' => 'Balcão']);
        $ana = User::factory()->create(['name' => 'Ana Souza']);
        $ticket = Ticket::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'sector_id' => Sector::factory()->create(['name' => 'Vendas'])->id,
            'assigned_user_id' => $ana->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
        ]);

        $this->assertSame("{$ticket->protocol} · {$contact->phoneLabel()} · Balcão · Vendas · Ana Souza", $this->line($ticket));
    }

    public function test_sem_setor_e_sem_responsavel_diz_fila_geral_e_nao_atribuido(): void
    {
        $contact = Contact::factory()->create(['wa_id' => '5516997524950@c.us']);
        $channel = Channel::factory()->create(['name' => 'Balcão']);
        $ticket = Ticket::factory()->create(['contact_id' => $contact->id, 'channel_id' => $channel->id, 'sector_id' => null, 'assigned_user_id' => null, 'status' => TicketStatus::AGUARDANDO]);

        $this->assertSame("{$ticket->protocol} · {$contact->phoneLabel()} · Balcão · Fila geral · Não atribuído", $this->line($ticket));
    }

    /** Setor e responsável são o fim da linha: cortá-los (só o `title` mostraria, e ele não funciona no toque) desfaz o objetivo de mostrá-los. */
    public function test_a_linha_quebra_em_vez_de_cortar_setor_e_responsavel(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::AGUARDANDO]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $html = Livewire::actingAs($admin)->test(Inbox::class)->call('select', $ticket->id)->html();

        $this->assertMatchesRegularExpression('/data-testid="header-line"[^>]*class="[^"]*\bbreak-words\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/data-testid="header-line"[^>]*class="[^"]*\btruncate\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/<span[^>]*class="[^"]*\btruncate\b[^"]*"[^>]*>\s*&nbsp;/', $html);
    }

    public function test_aberto_em_fica_no_tooltip_do_protocolo(): void
    {
        $opened = CarbonImmutable::parse('2026-11-14 17:56:00', 'UTC');
        $ticket = Ticket::factory()->create(['opened_at' => $opened, 'status' => TicketStatus::AGUARDANDO]);
        $expected = 'Aberto em '.$opened->setTimezone(config()->string('app.display_timezone'))->format('d/m/Y H:i');
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(Inbox::class)->call('select', $ticket->id)->assertSee($expected);
    }
}
