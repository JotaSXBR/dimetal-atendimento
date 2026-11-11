<?php

namespace Tests\Feature\Bot;

use App\Models\BotSession;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;
use App\Models\User;
use App\Services\Bot\Simulation\BotSimulator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BotSimulatorReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<int> contagens das tabelas que o bot de verdade mexe */
    private function counts(): array
    {
        return [Message::count(), Ticket::count(), TicketNote::count(), TicketEvent::count(), Contact::count(), BotSession::count(), DB::table('ticket_tag')->count()];
    }

    public function test_o_simulador_nao_escreve_nem_envia_nada(): void
    {
        $sector = Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        $tag = Tag::factory()->create();
        $agent = User::factory()->create(['active' => true]);
        $agent->sectors()->attach($sector);
        Storage::fake('local');
        Storage::fake('media');
        config(['media.disk' => 'media']);
        Storage::disk('local')->put('bot/catalogo.pdf', '%PDF');

        $graph = [
            'nodes' => array_map(fn (array $n): array => ['id' => $n[0], 'type' => $n[1], 'x' => 0, 'y' => 0, 'data' => $n[2]], [
                ['i', 'inicio', []],
                ['m', 'mensagem', ['texto' => 'Oi {{nome}}']],
                ['md', 'midia', ['path' => 'bot/catalogo.pdf', 'legenda' => 'Catálogo']],
                ['e', 'esperar', ['segundos' => 2]],
                ['mn', 'menu', ['texto' => 'Escolha', 'opcoes' => ['Comprar', 'Sair']]],
                ['ms', 'menu_setores', ['texto' => 'Setor?']],
                ['p', 'pergunta', ['texto' => 'CNPJ?', 'variavel' => 'cnpj', 'validacao' => 'cnpj']],
                ['p2', 'pergunta', ['texto' => 'Seu nome?', 'variavel' => 'nome_digitado']],
                ['sc', 'salvar_contato', ['campo' => 'name', 'variavel' => 'nome_digitado']],
                ['tg', 'etiquetar', ['tag_id' => $tag->id]],
                ['nt', 'nota', ['texto' => 'CNPJ {{cnpj}}']],
                ['c', 'condicao', ['kind' => 'horario']],
                ['tr', 'transferir', ['setor_id' => $sector->id, 'atribuir_a' => $agent->id]],
                ['en', 'encerrar', []],
            ]),
            'edges' => array_map(fn (array $e): array => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], [
                ['i', 'out', 'm'], ['m', 'out', 'md'], ['md', 'out', 'e'], ['e', 'out', 'mn'],
                ['mn', 'opt:1', 'ms'], ['mn', 'opt:2', 'en'], ['ms', 'setor', 'p'], ['p', 'out', 'p2'],
                ['p2', 'out', 'sc'], ['sc', 'out', 'tg'], ['tg', 'out', 'nt'], ['nt', 'out', 'c'],
                ['c', 'true', 'tr'], ['c', 'false', 'en'],
            ]),
        ];

        $before = $this->counts();
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(select|show|with)\b/i', $query->sql) !== 1) {
                $writes[] = $query->sql;
            }
        });
        $events = [];
        Event::listen('*', function (string $name) use (&$events): void {
            // Ler dispara estes (as escritas já são pegas pelo `DB::listen`); difundir, avisar ou gravar um modelo, não.
            if (preg_match('/^(eloquent\.(retrieved|booting|booted)\b|Illuminate\\\\Database\\\\Events\\\\)/', $name) !== 1) {
                $events[] = $name;
            }
        });
        Queue::fake();
        Bus::fake();
        Http::fake();

        $sim = app(BotSimulator::class);
        $outcomes = [];

        foreach (['aberto' => ['1', '1', '11222333000181', 'Ana'], 'fechado' => ['1', '1', '11222333000181', 'Ana'], 'real' => ['2']] as $clock => $replies) {
            $state = $sim->start($graph, 'Maria', $clock);
            foreach ($replies as $reply) {
                $sim->reply($graph, $state, $reply);
            }
            $outcomes[$clock] = $state->outcome;
        }

        $this->assertSame(['aberto' => 'fila', 'fechado' => 'encerrada', 'real' => 'encerrada'], $outcomes);
        $this->assertSame([], $writes, 'nenhuma consulta de escrita');
        Queue::assertNothingPushed();
        $this->assertSame([], $events, 'nenhum evento, broadcast ou alerta');
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        $this->assertSame($before, $this->counts());
        $this->assertSame(['bot/catalogo.pdf'], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('media')->allFiles());
    }
}
