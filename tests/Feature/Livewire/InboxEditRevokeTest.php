<?php

namespace Tests\Feature\Livewire;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** O menu da bolha: Editar e Apagar só aparecem na própria mensagem do painel e agem pelo WhatsApp. */
class InboxEditRevokeTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    private Message $mine;

    private Message $client;

    private int $wahaStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::fake(fn () => Http::response([], $this->wahaStatus));
        // Os jobs de apoio (visto, presença offline) não são o objeto deste teste e, com a fila `sync` do phpunit.xml, rodariam na hora e
        // chegariam ao WAHA falso; no CI (fila Redis) eles só ficam na fila. Faz o teste valer igual nos dois.
        Queue::fake();

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        RateLimiter::clear('message-action:'.$this->me->id);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create(['waha_session' => 'main'])->id,
            'contact_id' => Contact::factory()->create(['wa_id' => '5516997524950@c.us'])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
        $this->mine = $this->own();
        $this->client = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'CLI1', 'body' => 'Oi']);
    }

    /** @param array<string, mixed> $attributes */
    private function own(array $attributes = []): Message
    {
        return Message::factory()->for($this->ticket)->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $this->me->id,
            'wa_message_id' => 'MINE1', 'body' => 'Texto original', 'sent_at' => now(), ...$attributes,
        ]);
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    public function test_menu_so_mostra_editar_e_apagar_nas_proprias_do_painel(): void
    {
        $html = $this->inbox()->html();

        $this->assertStringContainsString('$parent.revoke('.$this->mine->id.')', $html);
        $this->assertStringContainsString('saveEdit('.$this->mine->id.')', $html);
        $this->assertStringNotContainsString('$parent.revoke('.$this->client->id.')', $html);
        $this->assertStringNotContainsString('saveEdit('.$this->client->id.')', $html);
    }

    public function test_item_editar_some_depois_de_15_min_mas_apagar_continua(): void
    {
        $this->mine->update(['sent_at' => now()->subSeconds(901)]);

        $html = $this->inbox()->html();

        $this->assertStringNotContainsString('saveEdit('.$this->mine->id.')', $html);
        $this->assertStringContainsString('$parent.revoke('.$this->mine->id.')', $html);
    }

    public function test_editar_pela_tela_chama_o_waha_e_o_texto_so_muda_pelo_eco(): void
    {
        $this->inbox()->call('editMessage', $this->mine->id, 'Texto novo')->assertSet('notice', null);

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['text'] === 'Texto novo');
        $this->assertSame('Texto original', $this->mine->refresh()->body);
    }

    public function test_apagar_pela_tela_chama_delete_e_nao_marca_apagada(): void
    {
        $this->inbox()->call('revoke', $this->mine->id);

        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
        $this->assertNull($this->mine->refresh()->revoked_at);
        $this->assertSame(1, TicketEvent::query()->where('type', 'message_revoked_by_agent')->count());
    }

    public function test_recusa_mostra_o_motivo_na_tela(): void
    {
        $this->wahaStatus = 400;

        $this->inbox()->call('editMessage', $this->mine->id, 'Texto novo')->assertSee('recusou');
    }

    public function test_mensagem_de_outro_atendente_ou_do_cliente_e_proibida(): void
    {
        $this->inbox()->call('editMessage', $this->client->id, 'x')->assertForbidden();
        $this->inbox()->call('revoke', $this->client->id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_rotulo_de_apagada_diz_quem_apagou(): void
    {
        $this->mine->update(['revoked_at' => now()]);
        $this->client->update(['revoked_at' => now()]);
        $phone = $this->own(['wa_message_id' => 'PH1', 'origin' => MessageOrigin::FORA_DO_PAINEL, 'author_user_id' => null, 'revoked_at' => now()]);

        $html = $this->inbox()->html();

        $this->assertStringContainsString('Mensagem apagada pelo atendente', $html);
        $this->assertStringContainsString('Mensagem apagada pelo cliente', $html);
        $this->assertStringContainsString('Mensagem apagada no celular', $html);
        $this->assertNotNull($phone->id);
    }
}
