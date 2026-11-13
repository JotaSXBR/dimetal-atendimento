<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** O play fica na mesma linha da onda (não no meio da onda mais o tempo) e a velocidade é um controle discreto na linha do tempo. */
class AudioPlayerLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function html(): string
    {
        $ticket = Ticket::factory()->for(Contact::factory()->create())->for(Channel::factory()->create())->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        Message::factory()->for($ticket)->create(['type' => 'media', 'body' => '', 'media_ref' => 'a.ogg', 'media_mime' => 'audio/ogg']);

        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)->call('select', $ticket->id)->html();
    }

    /** As classes do primeiro elemento que traz o atributo (ou o texto) dado dentro do player. */
    private function classesOf(string $html, string $marker): string
    {
        $this->assertSame(1, preg_match('/<[a-z]+[^>]*'.$marker.'[^>]*class="([^"]*)"|<[a-z]+[^>]*class="([^"]*)"[^>]*'.$marker.'/s', $html, $m), "{$marker} ausente");

        return $m[1] !== '' ? $m[1] : $m[2];
    }

    public function test_play_e_onda_dividem_a_mesma_linha_da_grade(): void
    {
        $html = $this->html();

        $this->assertStringContainsString('grid-cols-[auto_minmax(0,1fr)_auto]', $this->classesOf($html, 'data-audio-player'));
        $this->assertStringContainsString('row-start-1', $this->classesOf($html, 'aria-label="Reproduzir áudio"'));
        $this->assertStringContainsString('row-start-1', $this->classesOf($html, 'role="slider"'));
    }

    public function test_play_e_cheio_e_grande(): void
    {
        $html = $this->html();
        $classes = $this->classesOf($html, 'aria-label="Reproduzir áudio"');

        foreach (['size-10', 'rounded-full', 'bg-accent'] as $class) {
            $this->assertStringContainsString($class, $classes);
        }

        $this->assertMatchesRegularExpression('/aria-label="Reproduzir áudio".*?<svg[^>]*fill="currentColor"/s', $html, 'ícone de play cheio, não de contorno');
    }

    public function test_a_duracao_fica_na_linha_de_baixo_sob_a_onda(): void
    {
        $html = $this->html();

        $meta = $this->classesOf($html, 'data-testid="audio-meta"');
        $this->assertStringContainsString('col-start-2', $meta);
        $this->assertStringNotContainsString('justify-between', $meta, 'só a duração: a velocidade subiu para a linha da onda');
    }

    /** Como no WhatsApp: o controle de velocidade vem depois da onda, na mesma linha do play. */
    public function test_a_velocidade_fica_na_linha_da_onda_depois_dela(): void
    {
        $html = $this->html();

        $speed = $this->classesOf($html, 'aria-label="Velocidade de reprodução"');
        foreach (['row-start-1', 'col-start-3', 'ring-1', 'min-h-6'] as $class) {
            $this->assertStringContainsString($class, $speed, 'min-h-6 é o alvo de toque de 24 px (WCAG 2.5.8)');
        }

        $this->assertLessThan(strpos($html, 'aria-label="Velocidade de reprodução"'), strpos($html, 'role="slider"'), 'a velocidade vem depois da onda');

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $this->assertSame(0, (new \DOMXPath($dom))->query('//*[@data-testid="audio-meta"]//*[@aria-label="Velocidade de reprodução"]')->length);
    }
}
