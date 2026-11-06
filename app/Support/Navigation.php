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
            $item('Etiquetas', 'configuracoes.etiquetas', 'supervisor', $config, 'Atendimento', 'tag'),
            $item('Automação', 'automacao', 'supervisor', $config, 'Atendimento', 'cpu-chip', 'automacao*'),
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

        foreach ($this->visible($user) as $item) {
            $tree[$item->section][$item->group][] = $item;
        }

        return $tree;
    }

    /** @return list<NavItem> */
    private function visible(User $user): array
    {
        return array_values(array_filter(self::items(), fn (NavItem $item): bool => $item->gate === null || $user->can($item->gate)));
    }

    /** @return list<NavItem> */
    private function settingsItems(User $user): array
    {
        return array_values(array_filter($this->visible($user), fn (NavItem $item): bool => $item->section === self::SECTION_CONFIGURACAO));
    }

    /**
     * Os destinos fora de Configuração, que a sidebar lista direto.
     *
     * @return list<NavItem>
     */
    public function primary(User $user): array
    {
        return array_values(array_filter($this->visible($user), fn (NavItem $item): bool => $item->section !== self::SECTION_CONFIGURACAO));
    }

    /**
     * Grupo → itens de Configuração que o usuário pode abrir (a navegação do hub).
     *
     * @return array<string, list<NavItem>>
     */
    public function settingsGroups(User $user): array
    {
        $groups = [];

        foreach ($this->settingsItems($user) as $item) {
            $groups[$item->group][] = $item;
        }

        return $groups;
    }

    /** Destino do item "Configuração" do menu: o primeiro que o usuário pode abrir; `null` se nenhum. */
    public function settingsEntry(User $user): ?NavItem
    {
        return $this->settingsItems($user)[0] ?? null;
    }

    /** A rota atual é de Configuração (inclui `automacao.editar` e o retorno do Google, que não têm item próprio). */
    public function settingsActive(User $user): bool
    {
        foreach ($this->settingsItems($user) as $item) {
            if (request()->routeIs($item->match)) {
                return true;
            }
        }

        return false;
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
