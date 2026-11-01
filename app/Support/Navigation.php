<?php

namespace App\Support;

use App\Models\User;

/**
 * A lista única do que o menu mostra. A sidebar e a linha de irmãos das páginas de configuração leem daqui; o portão
 * de cada rota (`can:*` em `routes/web.php`) continua sendo a barreira real, e esta lista só decide o que aparece.
 */
class Navigation
{
    public const SECTION_ATENDIMENTO = 'Atendimento';

    public const SECTION_ACOMPANHAMENTO = 'Acompanhamento';

    public const SECTION_CONFIGURACAO = 'Configuração';

    /** @return list<NavItem> */
    public static function items(): array
    {
        $item = fn (string $label, string $route, ?string $gate, string $section, string $group, string $icon, ?string $match = null): NavItem => new NavItem($label, $route, $gate, $section, $group, $icon, $match ?? $route);
        $work = self::SECTION_ATENDIMENTO;
        $follow = self::SECTION_ACOMPANHAMENTO;
        $config = self::SECTION_CONFIGURACAO;

        return [
            $item('Fila', 'inbox', null, $work, '', 'inbox'),
            $item('Contatos', 'contatos', null, $work, '', 'user-group'),
            $item('Histórico', 'historico', null, $work, '', 'archive-box'),
            $item('Relatórios', 'relatorios', 'supervisor', $follow, '', 'chart-bar'),
            $item('Auditoria', 'auditoria', 'admin', $follow, '', 'shield-check'),
            $item('Setores', 'configuracoes.setores', 'admin', $config, 'Atendimento', 'squares-2x2'),
            $item('Horário e SLA', 'configuracoes.horario', 'admin', $config, 'Atendimento', 'clock'),
            $item('Respostas rápidas', 'configuracoes.respostas', 'supervisor', $config, 'Atendimento', 'bolt'),
            $item('Usuários', 'configuracoes.usuarios', 'supervisor', $config, 'Equipe', 'users'),
            $item('Canais', 'configuracoes.canais', 'admin', $config, 'Conexões', 'chat-bubble-left-right'),
            $item('Google Contatos', 'configuracoes.google', 'admin', $config, 'Conexões', 'link', 'configuracoes.google*'),
            $item('Empresa e assinatura', 'configuracoes.empresa', 'admin', $config, 'Empresa', 'building-office'),
            $item('Anexos e retenção', 'configuracoes.retencao', 'admin', $config, 'Empresa', 'trash'),
        ];
    }

    public function find(string $routeName): ?NavItem
    {
        foreach (self::items() as $item) {
            if ($item->route === $routeName) {
                return $item;
            }
        }

        return null;
    }

    /**
     * Seção → grupo (`''` quando a seção não tem grupos) → itens que o usuário pode abrir. Seção sem item visível não aparece.
     *
     * @return array<string, array<string, list<NavItem>>>
     */
    public function tree(User $user): array
    {
        $tree = [];

        foreach (self::items() as $item) {
            if ($item->gate === null || $user->can($item->gate)) {
                $tree[$item->section][$item->group][] = $item;
            }
        }

        return $tree;
    }

    /**
     * Os outros itens do grupo que o usuário pode abrir, incluindo o atual; vazio quando sobra menos de dois.
     *
     * @return list<NavItem>
     */
    public function siblings(User $user, string $routeName): array
    {
        $current = $this->find($routeName);

        if ($current === null || $current->group === '') {
            return [];
        }

        $items = $this->tree($user)[$current->section][$current->group] ?? [];

        return count($items) < 2 ? [] : $items;
    }
}
