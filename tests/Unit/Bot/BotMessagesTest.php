<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\BotMessages;
use App\Services\Bot\Flow\FlowGraph;
use PHPUnit\Framework\TestCase;

class BotMessagesTest extends TestCase
{
    /** @param  array<string, mixed>  $messages */
    private function flow(array $messages): FlowGraph
    {
        return FlowGraph::fromArray(['nodes' => [], 'edges' => [], 'settings' => ['mensagens' => $messages]]);
    }

    public function test_as_chaves_e_os_padroes_batem(): void
    {
        $this->assertSame(BotMessages::KEYS, array_keys(BotMessages::DEFAULTS));
        $this->assertCount(5, BotMessages::KEYS);
    }

    public function test_sem_settings_vale_o_padrao_de_cada_chave(): void
    {
        $flow = FlowGraph::fromArray(['nodes' => [], 'edges' => []]);

        foreach (BotMessages::KEYS as $key) {
            $this->assertSame(BotMessages::DEFAULTS[$key], BotMessages::text($flow, $key));
        }
    }

    public function test_texto_proprio_vence_o_padrao(): void
    {
        $this->assertSame('Aguarde!', BotMessages::text($this->flow(['opcao_invalida' => 'Aguarde!']), 'opcao_invalida'));
    }

    public function test_texto_em_branco_volta_ao_padrao(): void
    {
        $this->assertSame(BotMessages::DEFAULTS['opcao_invalida'], BotMessages::text($this->flow(['opcao_invalida' => "  \n "]), 'opcao_invalida'));
    }

    public function test_rodape_vazio_significa_sem_rodape(): void
    {
        $this->assertSame('', BotMessages::text($this->flow(['rodape_sair' => '']), 'rodape_sair'));
    }

    public function test_valor_que_nao_e_texto_volta_ao_padrao(): void
    {
        $this->assertSame(BotMessages::DEFAULTS['rodape_sair'], BotMessages::text($this->flow(['rodape_sair' => 123]), 'rodape_sair'));
        $this->assertSame(BotMessages::DEFAULTS['opcao_invalida'], BotMessages::text($this->flow(['opcao_invalida' => ['x']]), 'opcao_invalida'));
    }
}
