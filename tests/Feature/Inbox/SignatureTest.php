<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\Signature;
use App\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SignatureTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        RateLimiter::clear('send-message:1');
        CompanySettings::save(['name' => 'Dimetal Distribuidora', 'signatureEnabled' => true, 'signatureTemplate' => CompanySettings::DEFAULT_TEMPLATE, 'signaturePosition' => 'inicio', 'slaMinutes' => 10]);

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Jessé']);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'sector_id' => Sector::factory()->create(['name' => 'Comercial'])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    private function save(array $override): void
    {
        CompanySettings::save(array_replace(['name' => 'Dimetal Distribuidora', 'signatureEnabled' => true, 'signatureTemplate' => CompanySettings::DEFAULT_TEMPLATE, 'signaturePosition' => 'inicio', 'slaMinutes' => 10], $override));
    }

    public function test_assinatura_no_inicio_como_no_chatwoot(): void
    {
        $this->assertSame("*Jessé @ Dimetal Distribuidora:*\n\nBom dia! Tudo bem?", Signature::apply('Bom dia! Tudo bem?', $this->me, null));
    }

    public function test_assinatura_no_fim_com_setor(): void
    {
        $this->save(['signatureTemplate' => '_{{atendente}}_ - {{setor}}', 'signaturePosition' => 'fim']);

        $this->assertSame("Bom dia!\n\n_Jessé_ - Comercial", Signature::apply('Bom dia!', $this->me, Sector::query()->firstOrFail()));
    }

    public function test_desligada_no_geral_ou_pelo_atendente_ou_sem_texto(): void
    {
        $this->assertSame('Oi', Signature::apply('Oi', $this->me->forceFill(['sign_messages' => false]), null));

        $this->save(['signatureEnabled' => false]);
        $this->assertSame('Oi', Signature::apply('Oi', $this->me->forceFill(['sign_messages' => true]), null));

        $this->save(['signatureEnabled' => true]);
        $this->assertSame('', Signature::apply('', $this->me, null));
    }

    public function test_modelo_que_vira_vazio_nao_deixa_linhas_sobrando(): void
    {
        $this->save(['signatureTemplate' => '{{setor}}']);

        $this->assertSame('Oi', Signature::apply('Oi', $this->me, null));
    }

    public function test_envio_pelo_painel_grava_o_texto_ja_assinado(): void
    {
        Livewire::actingAs($this->me)->test(Inbox::class)->set('ticketId', $this->ticket->id)->set('draft', 'Bom dia!')->call('send');

        $this->assertSame("*Jessé @ Dimetal Distribuidora:*\n\nBom dia!", Message::query()->firstOrFail()->body);
    }

    public function test_o_atendente_liga_e_desliga_e_a_escolha_persiste(): void
    {
        $component = Livewire::actingAs($this->me)->test(Inbox::class)->set('ticketId', $this->ticket->id)->assertSet('sign', true)->call('toggleSign')->assertSet('sign', false);

        $this->assertFalse($this->me->fresh()?->sign_messages);

        $component->set('draft', 'Sem assinatura')->call('send');
        $this->assertSame('Sem assinatura', Message::query()->firstOrFail()->body);

        Livewire::actingAs($this->me->fresh() ?? $this->me)->test(Inbox::class)->assertSet('sign', false);
    }

    public function test_legenda_de_arquivo_nao_leva_assinatura(): void
    {
        Livewire::actingAs($this->me)->test(Inbox::class)->set('ticketId', $this->ticket->id)
            ->set('upload', UploadedFile::fake()->createWithContent('foto.jpg', self::JPEG))
            ->call('commitUpload', $this->ticket->id)
            ->set('draft', 'Segue a foto')
            ->call('send');

        $this->assertSame('Segue a foto', Message::query()->where('type', 'media')->firstOrFail()->body);
    }

    public function test_marcadores_no_nome_do_atendente_nao_desfazem_a_formatacao(): void
    {
        $this->me->name = 'Jé*s_s~é';

        $this->assertSame("*Jéssé @ Dimetal Distribuidora:*\n\nOi", Signature::apply('Oi', $this->me, null));
    }

    public function test_rascunho_mais_assinatura_acima_de_4096_e_recusado_sem_enviar(): void
    {
        Livewire::actingAs($this->me)->test(Inbox::class)->set('ticketId', $this->ticket->id)
            ->set('draft', str_repeat('a', 4090))->call('send')->assertHasErrors(['draft']);

        $this->assertSame(0, Message::query()->count());
    }
}
