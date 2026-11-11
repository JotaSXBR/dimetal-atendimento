<?php

namespace App\Services\Bot;

use App\Services\Bot\Flow\FlowGraph;

/**
 * Os textos que o bot diz sozinho, editáveis por workflow (`settings.mensagens` do grafo). Ausente ou em branco vale o padrão
 * (o texto de antes de existir a edição); só o rodapé do `#sair` pode ficar vazio de propósito, e então some.
 * `encaminhamento_*`: ao entregar à fila (`BotEngine::handoff`); `opcao_invalida` e `rodape_sair`: menus (`ChoiceNode`);
 * `resposta_invalida`: `PerguntaNode`.
 */
final class BotMessages
{
    public const MAX_LENGTH = 1000;

    public const KEYS = ['encaminhamento_setor', 'encaminhamento_geral', 'opcao_invalida', 'resposta_invalida', 'rodape_sair'];

    public const DEFAULTS = [
        'encaminhamento_setor' => 'Vou encaminhar você para o setor {{setor}}. Seu protocolo é {{protocolo}}.',
        'encaminhamento_geral' => 'Vou encaminhar você para um atendente. Seu protocolo é {{protocolo}}.',
        'opcao_invalida' => 'Opção inválida. Responda com o número de uma das opções.',
        'resposta_invalida' => 'Resposta inválida. Tente de novo.',
        'rodape_sair' => 'Para falar com um atendente digite #sair',
    ];

    public static function text(FlowGraph $flow, string $key): string
    {
        $custom = $flow->message($key);

        if ($custom === '' && $key === 'rodape_sair') {
            return '';
        }

        return $custom !== null && trim($custom) !== '' ? $custom : (self::DEFAULTS[$key] ?? '');
    }
}
