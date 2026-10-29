<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Jobs\ResolveMessageMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(array $payload, string $event = 'message', ?string $envelopeId = null): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => $envelopeId ?? 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => $event,
            'session' => 'main',
            'payload' => ['event' => $event, 'session' => 'main', 'payload' => $payload],
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function inbound(string $id = 'false_5516997524950@c.us_ABC', array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'from' => self::PEER,
            'fromMe' => false,
            'body' => 'Quero um orcamento',
            'timestamp' => 1_793_000_000,
            'hasMedia' => false,
            '_data' => ['Info' => ['PushName' => 'Maria Souza']],
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function fromPhone(string $id = 'true_5516997524950@c.us_XYZ', array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'from' => '5516999990001@c.us',
            'to' => self::PEER,
            'fromMe' => true,
            'body' => 'Segue o orcamento',
            'timestamp' => 1_793_000_100,
            'hasMedia' => false,
        ], $extra);
    }

    private function ingest(WahaEvent $event): ?Message
    {
        return app(IngestMessage::class)($event);
    }

    public function test_mesmo_id_com_prefixo_de_chat_diferente_nao_duplica_a_mensagem(): void
    {
        $ingest = app(IngestMessage::class);

        $ingest($this->event($this->inbound('false_5516997524950@c.us_3EB0SAMEID0001')));
        $ingest($this->event($this->inbound('false_16449842241553@lid_3EB0SAMEID0001')));

        $this->assertSame(1, Message::query()->where('wa_message_id', '3EB0SAMEID0001')->count());
    }

    public function test_mensagem_recebida_cria_contato_ticket_e_mensagem(): void
    {
        $message = $this->ingest($this->event($this->inbound()));

        $this->assertNotNull($message);
        $this->assertSame(self::PEER, Contact::query()->sole()->wa_id);
        $this->assertSame('Maria Souza', Contact::query()->sole()->name);

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::AGUARDANDO, $ticket->status);
        $this->assertNull($ticket->sector_id);
        $this->assertNull($ticket->assigned_user_id);
        $this->assertSame(1_793_000_000, $ticket->last_inbound_at?->getTimestamp());

        $this->assertSame(MessageDirection::IN, $message->direction);
        $this->assertSame(MessageOrigin::CONTATO, $message->origin);
        $this->assertSame('Quero um orcamento', $message->body);
        $this->assertSame(1_793_000_000, $message->sent_at->getTimestamp());
        $this->assertSame(['opened', 'message_in'], $ticket->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_reenvio_do_mesmo_webhook_nao_duplica(): void
    {
        $this->ingest($this->event($this->inbound(), 'message', 'evt_a'));
        $this->ingest($this->event($this->inbound(), 'message', 'evt_b'));

        $this->assertSame(1, Message::query()->count());
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_message_e_message_any_da_mesma_mensagem_nao_duplicam(): void
    {
        $this->ingest($this->event($this->inbound(), 'message'));
        $second = $this->ingest($this->event($this->inbound(), 'message.any'));

        $this->assertNull($second);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_message_any_recebida_e_ignorada_mas_from_me_e_gravada(): void
    {
        $this->assertNull($this->ingest($this->event($this->inbound(), 'message.any')));
        $this->assertSame(0, Message::query()->count());

        $this->assertNotNull($this->ingest($this->event($this->fromPhone(), 'message.any')));
        $this->assertSame(1, Message::query()->count());
    }

    public function test_from_me_vira_fora_do_painel_no_ticket_aberto_sem_criar_outro(): void
    {
        $this->ingest($this->event($this->inbound()));

        $message = $this->ingest($this->event($this->fromPhone(), 'message.any'));

        $this->assertNotNull($message);
        $this->assertSame(MessageOrigin::FORA_DO_PAINEL, $message->origin);
        $this->assertSame(MessageDirection::OUT, $message->direction);
        $this->assertSame(DeliveryStatus::SENT, $message->delivery_status);
        $this->assertSame(1, Ticket::query()->count());

        $ticket = Ticket::query()->sole();
        $this->assertSame(1_793_000_100, $ticket->last_outbound_at?->getTimestamp());
        $this->assertSame(1_793_000_100, $ticket->first_human_reply_at?->getTimestamp());
        $this->assertSame('message_out', $ticket->events()->orderByDesc('id')->value('type'));
    }

    public function test_from_me_sem_ticket_aberto_abre_em_atendimento_sem_responsavel(): void
    {
        $this->ingest($this->event($this->fromPhone(), 'message.any'));

        $ticket = Ticket::query()->sole();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->status);
        $this->assertNull($ticket->assigned_user_id);
        $this->assertNotNull($ticket->first_human_reply_at);
    }

    public function test_eco_completa_o_id_da_mensagem_pendente_do_painel_sem_duplicar(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::PEER]);
        $ticket = Ticket::factory()->for($contact)->for($this->channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $pending = Message::factory()->for($ticket)->create([
            'wa_message_id' => null,
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'body' => 'Segue o orcamento',
        ]);

        $result = $this->ingest($this->event($this->fromPhone('true_5516997524950@c.us_ECO'), 'message.any'));

        $this->assertTrue($result?->is($pending));
        $this->assertSame('ECO', $pending->fresh()?->wa_message_id);
        $this->assertSame(MessageOrigin::PAINEL, $pending->fresh()?->origin);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_pendente_antiga_ou_de_outro_texto_nao_casa_com_o_eco(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::PEER]);
        $ticket = Ticket::factory()->for($contact)->for($this->channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $old = Message::factory()->for($ticket)->create([
            'wa_message_id' => null,
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'body' => 'Segue o orcamento',
        ]);
        $old->forceFill(['created_at' => now()->subMinutes(5)])->save();

        $result = $this->ingest($this->event($this->fromPhone('true_5516997524950@c.us_ECO'), 'message.any'));

        $this->assertFalse($result?->is($old));
        $this->assertSame(MessageOrigin::FORA_DO_PAINEL, $result?->origin);
        $this->assertSame(2, Message::query()->count());
    }

    public function test_mensagem_de_grupo_e_broadcast_sao_ignoradas(): void
    {
        $group = $this->inbound('false_1203630@g.us_A', ['from' => '1203630@g.us']);
        $status = $this->inbound('false_status@broadcast_B', ['from' => 'status@broadcast']);

        $this->assertNull($this->ingest($this->event($group)));
        $this->assertNull($this->ingest($this->event($status)));
        $this->assertSame(0, Message::query()->count());
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_payload_sem_id_e_ignorado(): void
    {
        $payload = $this->inbound();
        unset($payload['id']);

        $this->assertNull($this->ingest($this->event($payload)));
        $this->assertSame(0, Message::query()->count());
    }

    public function test_payload_sem_body_e_sem_midia_e_ignorado_sem_abrir_ticket(): void
    {
        $payload = $this->inbound();
        unset($payload['body']);

        $this->assertNull($this->ingest($this->event($payload)));
        $this->assertSame(0, Message::query()->count());
        $this->assertSame(0, Ticket::query()->count());
    }

    public function test_payload_sem_timestamp_usa_o_horario_de_recebimento(): void
    {
        $payload = $this->inbound();
        unset($payload['timestamp']);
        $event = $this->event($payload);

        $message = $this->ingest($event);

        $this->assertSame($event->received_at->getTimestamp(), $message?->sent_at->getTimestamp());
    }

    public function test_timestamp_zero_nao_vira_1970(): void
    {
        $event = $this->event($this->inbound('false_5516997524950@c.us_ZERO', ['timestamp' => 0]));

        $message = $this->ingest($event);

        $this->assertSame($event->received_at->getTimestamp(), $message?->sent_at->getTimestamp());
    }

    public function test_mensagem_antiga_nao_recua_o_ultimo_horario_do_ticket(): void
    {
        $this->ingest($this->event($this->inbound('false_5516997524950@c.us_NEW', ['timestamp' => 1_793_000_100])));
        $this->ingest($this->event($this->inbound('false_5516997524950@c.us_OLD', ['timestamp' => 1_793_000_000])));

        $this->assertSame(1_793_000_100, Ticket::query()->sole()->last_inbound_at?->getTimestamp());
    }

    public function test_pendente_que_falhou_nao_engole_o_eco_do_celular(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::PEER]);
        $ticket = Ticket::factory()->for($contact)->for($this->channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $failed = Message::factory()->for($ticket)->create([
            'wa_message_id' => null,
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'body' => 'Segue o orcamento',
            'delivery_status' => DeliveryStatus::FAILED,
        ]);

        $result = $this->ingest($this->event($this->fromPhone('true_5516997524950@c.us_ECO'), 'message.any'));

        $this->assertFalse($result?->is($failed));
        $this->assertSame(MessageOrigin::FORA_DO_PAINEL, $result?->origin);
    }

    public function test_eco_casado_com_pendente_atualiza_o_ticket(): void
    {
        $contact = Contact::factory()->create(['wa_id' => self::PEER]);
        $ticket = Ticket::factory()->for($contact)->for($this->channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        Message::factory()->for($ticket)->create([
            'wa_message_id' => null,
            'direction' => MessageDirection::OUT,
            'origin' => MessageOrigin::PAINEL,
            'body' => 'Segue o orcamento',
        ]);

        $this->ingest($this->event($this->fromPhone('true_5516997524950@c.us_ECO'), 'message.any'));

        $this->assertNotNull($ticket->fresh()?->first_human_reply_at);
        $this->assertNotNull($ticket->fresh()?->last_outbound_at);
    }

    public function test_abertura_de_ticket_e_serializada_por_contato_e_canal(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->ingest($this->event($this->inbound()));

        $locks = array_filter($queries, fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock'));
        $this->assertGreaterThanOrEqual(2, count($locks), 'contato e ticket devem ser serializados');
    }

    public function test_midia_grava_a_mensagem_sem_corpo_e_sem_baixar_arquivo(): void
    {
        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_IMG', [
            'hasMedia' => true,
            'body' => 'legenda',
        ])));

        $this->assertSame('media', $message?->type);
        $this->assertSame('', $message?->body);
    }

    public function test_midia_com_filename_guarda_a_referencia(): void
    {
        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_AUD', [
            'hasMedia' => true,
            'media' => ['filename' => 'abc.ogg', 'mimetype' => 'audio/ogg; codecs=opus', 'url' => 'http://localhost:3000/api/files/abc.ogg'],
        ])));

        $this->assertSame('media', $message?->type);
        $this->assertSame('abc.ogg', $message->media_ref);
        $this->assertSame('abc.ogg', $message->media_name);
        $this->assertSame('audio/ogg', $message->media_mime);
    }

    public function test_toda_midia_pede_a_confirmacao_do_arquivo_mesmo_com_nome_no_evento(): void
    {
        $this->ingest($this->event($this->inbound('false_5516997524950@c.us_NOREF', ['hasMedia' => true])));
        $this->ingest($this->event($this->inbound('false_5516997524950@c.us_REF', ['hasMedia' => true, 'media' => ['filename' => 'r.jpg']])));
        $this->ingest($this->event($this->inbound('false_5516997524950@c.us_TXT')));

        Queue::assertPushed(ResolveMessageMediaJob::class, 2);
    }

    public function test_midia_so_com_url_enc_fica_sem_referencia(): void
    {
        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_ENC', [
            'hasMedia' => true,
            'media' => ['url' => 'https://mmg.whatsapp.net/x.enc', 'mimetype' => 'image/jpeg'],
        ])));

        $this->assertSame('media', $message?->type);
        $this->assertNull($message->media_ref);
        $this->assertSame('image/jpeg', $message->media_mime);
    }

    public function test_filename_com_barra_ou_ponto_ponto_e_descartado(): void
    {
        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_EVIL', [
            'hasMedia' => true,
            'media' => ['filename' => '../../etc/passwd'],
        ])));

        $this->assertNull($message?->media_ref);
    }

    public function test_mensagem_indisponivel_de_visualizacao_unica_pelo_tipo_do_payload_vira_marcada(): void
    {
        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_UNAV', [
            'body' => '',
            '_data' => ['Info' => ['PushName' => 'Maria Souza'], 'IsUnavailable' => true, 'UnavailableType' => 'view_once'],
        ])));

        $this->assertSame('view_once', $message?->type);
    }

    public function test_imagem_de_visualizacao_unica_vira_aviso_sem_guardar_o_conteudo(): void
    {
        foreach (['IsViewOnce', 'IsViewOnceV2', 'IsViewOnceV2Extension'] as $i => $flag) {
            $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_VO'.$i, [
                'hasMedia' => true,
                'media' => ['filename' => 'vo'.$i.'.jpeg', 'mimetype' => 'image/jpeg'],
                '_data' => ['Info' => ['PushName' => 'Maria Souza'], 'IsViewOnce' => false, $flag => true],
            ])));

            $this->assertSame('view_once', $message?->type, $flag);
            $this->assertNull($message->media_ref, $flag);
            $this->assertNull($message->media_mime, $flag);
        }

        Queue::assertNotPushed(ResolveMessageMediaJob::class);
    }

    public function test_contato_vcard_vira_mensagem_com_nome_e_telefone(): void
    {
        $vcard = 'BEGIN:VCARD
VERSION:3.0
FN:Maria Souza
TEL;type=CELL;waid=5516999990001:+55 16 99999-0001
END:VCARD';

        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_VC', ['body' => null, 'vCards' => [$vcard]])));

        $this->assertSame('contact', $message?->type);
        $this->assertStringContainsString('Maria Souza', $message->body);
        $this->assertStringContainsString('+55 16 99999-0001', $message->body);
    }

    public function test_varios_contatos_no_mesmo_envio_aparecem_todos(): void
    {
        $cards = [
            'BEGIN:VCARD
FN:Ana
TEL:+55 11 90000-0001
END:VCARD',
            'BEGIN:VCARD
FN:Beto
TEL:+55 11 90000-0002
END:VCARD',
        ];

        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_VC2', ['body' => null, 'vCards' => $cards])));

        $this->assertStringContainsString('Ana', (string) $message?->body);
        $this->assertStringContainsString('Beto', (string) $message->body);
    }

    public function test_visualizacao_unica_vira_mensagem_marcada(): void
    {
        $message = $this->ingest($this->event($this->inbound('false_5516997524950@c.us_ONCE', [
            'body' => '',
            '_data' => ['Info' => ['PushName' => 'Maria Souza'], 'Message' => ['viewOnceMessageV2' => ['message' => []]]],
        ])));

        $this->assertSame('view_once', $message?->type);
    }

    public function test_apos_encerrar_mensagem_nova_abre_ticket_novo(): void
    {
        $this->ingest($this->event($this->inbound('false_5516997524950@c.us_ONE')));
        Ticket::query()->update(['status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);

        $this->ingest($this->event($this->inbound('false_5516997524950@c.us_TWO')));

        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(1, Ticket::query()->open()->count());
    }

    public function test_evento_sem_canal_e_ignorado(): void
    {
        $event = $this->event($this->inbound());
        $event->update(['channel_id' => null]);

        $this->assertNull($this->ingest($event->fresh() ?? $event));
    }
}
