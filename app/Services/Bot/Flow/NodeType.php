<?php

namespace App\Services\Bot\Flow;

/** Os blocos do workflow. O valor é o `type` gravado no grafo. */
enum NodeType: string
{
    case Inicio = 'inicio';
    case Mensagem = 'mensagem';
    case MenuSetores = 'menu_setores';
    case Menu = 'menu';
    case Pergunta = 'pergunta';
    case Condicao = 'condicao';
    case Esperar = 'esperar';
    case Midia = 'midia';
    case SalvarContato = 'salvar_contato';
    case Etiquetar = 'etiquetar';
    case Transferir = 'transferir';
    case Encerrar = 'encerrar';

    /** O bloco para e espera a resposta do cliente. */
    public function waits(): bool
    {
        return in_array($this, [self::MenuSetores, self::Menu, self::Pergunta], true);
    }

    /** O fluxo termina aqui. */
    public function isFinal(): bool
    {
        return $this === self::Transferir || $this === self::Encerrar;
    }

    public function label(): string
    {
        return match ($this) {
            self::Inicio => 'Início',
            self::Mensagem => 'Mensagem',
            self::MenuSetores => 'Menu de setores',
            self::Menu => 'Menu',
            self::Pergunta => 'Pergunta',
            self::Condicao => 'Condição',
            self::Esperar => 'Esperar',
            self::Midia => 'Enviar mídia',
            self::SalvarContato => 'Salvar no contato',
            self::Etiquetar => 'Etiquetar',
            self::Transferir => 'Transferir para setor',
            self::Encerrar => 'Encerrar',
        };
    }

    /**
     * As portas de saída que precisam estar ligadas. `erro` é sempre opcional (sem ligação, o cliente vai à fila padrão).
     *
     * @return list<string>
     */
    public function ports(NodeData $data): array
    {
        return match ($this) {
            self::Transferir, self::Encerrar => [],
            self::MenuSetores => ['setor'],
            self::Condicao => ['true', 'false'],
            self::Menu => array_map(fn (int $i): string => 'opt:'.$i, range(1, max(1, count($data->list('opcoes'))))),
            default => ['out'],
        };
    }
}
