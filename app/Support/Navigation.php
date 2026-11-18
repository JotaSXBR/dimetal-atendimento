<?php

namespace App\Support;

use App\Models\User;

/**
 * A lista única do que o menu mostra. A sidebar e a navegação das páginas de configuração leem daqui; o portão
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
            // Supervisor e admin mexem nos workflows toda semana: fica na barra, não dentro de Configuração. A barra é plana (a seção só separa
            // Configuração do resto), então `$work` ou `$follow` não muda o que aparece; a ordem do array é a ordem da barra.
            $item('Automação', 'automacao', 'supervisor', $work, '', 'cpu-chip', 'automacao*'),
            $item('Auditoria', 'auditoria', 'admin', $follow, '', 'shield-check', 'auditoria*'),
            $item('Setores', 'configuracoes.setores', 'admin', $config, 'Atendimento', 'squares-2x2'),
            $item('Horário e SLA', 'configuracoes.horario', 'admin', $config, 'Atendimento', 'clock'),
            $item('Respostas rápidas', 'configuracoes.respostas', 'supervisor', $config, 'Atendimento', 'bolt'),
            $item('Etiquetas', 'configuracoes.etiquetas', 'supervisor', $config, 'Atendimento', 'tag'),
            $item('Usuários', 'configuracoes.usuarios', 'supervisor', $config, 'Equipe', 'users'),
            $item('Canais', 'configuracoes.canais', 'admin', $config, 'Conexões', 'chat-bubble-left-right'),
            $item('Google Contatos', 'configuracoes.google', 'admin', $config, 'Conexões', 'link', 'configuracoes.google*'),
            $item('Empresa e assinatura', 'configuracoes.empresa', 'admin', $config, 'Empresa', 'building-office'),
            $item('Anexos e retenção', 'configuracoes.retencao', 'admin', $config, 'Empresa', 'trash'),
            $item('Backup do banco', 'configuracoes.backups', 'admin', $config, 'Empresa', 'circle-stack', 'configuracoes.backups*'),
            $item('Sistema', 'configuracoes.sistema', 'admin', $config, 'Empresa', 'arrow-path'),
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
     * Tudo que o usuário pode abrir (a barra e Configuração): também a lista da busca Ctrl+K.
     *
     * @return list<NavItem>
     */
    public function visible(User $user): array
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

    /**
     * O menu de Minha conta (todo usuário abre; fora da lista de `items()` para não entrar na barra nem na busca).
     *
     * @return array<string, list<NavItem>>
     */
    public function accountGroups(): array
    {
        $item = fn (string $label, string $route, string $icon): NavItem => new NavItem($label, $route, null, 'Minha conta', 'Minha conta', $icon, $route);

        return ['Minha conta' => [
            $item('Perfil', 'profile.edit', 'user'),
            $item('Segurança', 'security.edit', 'shield-check'),
            $item('Aparência', 'appearance.edit', 'sun'),
            $item('Meus alertas', 'alerts.edit', 'bell'),
        ]];
    }

    /** Destino do item "Configuração" do menu: o primeiro que o usuário pode abrir; `null` se nenhum. */
    public function settingsEntry(User $user): ?NavItem
    {
        return $this->settingsItems($user)[0] ?? null;
    }

    /** A rota atual é de Configuração (inclui o retorno do Google, que não tem item próprio). A Automação tem item próprio na barra e não conta. */
    public function settingsActive(User $user): bool
    {
        foreach ($this->settingsItems($user) as $item) {
            if (request()->routeIs($item->match)) {
                return true;
            }
        }

        return false;
    }
}
