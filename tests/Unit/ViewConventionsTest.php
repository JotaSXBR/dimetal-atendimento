<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Convenções de UI que não podem voltar para trás (selo único, botões só do Flux). */
class ViewConventionsTest extends TestCase
{
    /** @return array<string, string> caminho relativo → conteúdo, de todas as views Blade */
    private function views(): array
    {
        $root = dirname(__DIR__, 2).'/resources/views';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $out = [];

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $out[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1))] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    public function test_views_nao_usam_mais_status_color_nem_selo_a_mao(): void
    {
        // Contadores numéricos (não são estado): o selo de SLA da sidebar e o contador do sino.
        $contadores = ['layouts/app/sidebar.blade.php', 'livewire/alerts/bell.blade.php'];

        foreach ($this->views() as $path => $html) {
            $this->assertStringNotContainsString('$statusColor', $html, "{$path}: o tom vem do enum (tone()), não de closure local");
            $this->assertDoesNotMatchRegularExpression('/<flux:badge[^>]*\s:?color=/', $html, "{$path}: flux:badge com cor da paleta; use <x-status-badge>");

            if ($path === 'components/status-badge.blade.php' || in_array($path, $contadores, true)) {
                continue;
            }

            foreach (explode("\n", $html) as $n => $line) {
                $this->assertDoesNotMatchRegularExpression('/rounded-full[^"\']*\spx-[^"\']*(bg|text)-(ok|warn|danger|bot)\b|(bg|text)-(ok|warn|danger|bot)\b[^"\']*\srounded-full[^"\']*\spx-/', $line, $path.':'.($n + 1).': pílula de estado feita à mão; use <x-status-badge>');
            }
        }
    }

    /**
     * Piso de legibilidade: nenhum texto abaixo de 12 px (`text-xs`) nas views nem nas classes montadas em JS. Pega `text-[10px]`,
     * `text-[8.5px]` e `text-[0.6875rem]`; texto de 10 e 11 px não se lê em tela comum nem de celular.
     */
    public function test_nenhum_texto_abaixo_de_12px(): void
    {
        $small = '/text-\[(?:(?:[0-9]|1[01])(?:\.\d+)?px|0?\.(?:[0-6]\d*|7[0-4]\d*)rem)\]/';

        // A própria guarda: o que ela deve e o que não deve pegar.
        foreach (['text-[10px]', 'text-[11px]', 'text-[8.5px]', 'text-[9px]', 'text-[0.6875rem]'] as $bad) {
            $this->assertSame(1, preg_match($small, $bad), $bad);
        }
        foreach (['text-[12px]', 'text-[13px]', 'text-[0.75rem]', 'text-xs', 'text-[var(--tag-fg)]'] as $good) {
            $this->assertSame(0, preg_match($small, $good), $good);
        }

        foreach ($this->views() as $path => $html) {
            $this->assertDoesNotMatchRegularExpression($small, $html, "{$path}: texto abaixo de 12 px (use text-xs)");
        }

        foreach (glob(dirname(__DIR__, 2).'/resources/js/*.js') ?: [] as $file) {
            $this->assertDoesNotMatchRegularExpression($small, (string) file_get_contents($file), basename($file).': texto abaixo de 12 px');
        }
    }

    /**
     * Piso de toque: link de protocolo sublinhado e os controles pequenos conhecidos têm alvo de ao menos 24 px (WCAG 2.2, 2.5.8).
     * É uma guarda de convenção: vê o padrão `underline-offset-2` do app (link novo sublinhado de outro jeito não entra); o script de
     * auditoria `docs/superpowers/audit/ux-audit.js` mede todos os alvos no navegador.
     */
    public function test_alvos_de_clique_pequenos_tem_ao_menos_24px(): void
    {
        $min24 = '/(?<![\w-])min-h-6!?(?![\w-])/';

        // A própria guarda: `min-h-60` e `min-h-64` não valem como 24 px.
        $this->assertSame(1, preg_match($min24, 'inline-flex min-h-6 items-center'));
        $this->assertSame(0, preg_match($min24, 'inline-flex min-h-60 items-center'));
        $this->assertSame(0, preg_match($min24, 'min-h-64'));

        foreach ($this->views() as $path => $html) {
            preg_match_all('/class="([^"]*underline-offset-2[^"]*)"/', $html, $links);

            foreach ($links[1] as $class) {
                $this->assertMatchesRegularExpression($min24, $class, "{$path}: link sublinhado precisa de min-h-6 (alvo de 24 px)");
            }
        }

        $views = $this->views();
        $this->assertMatchesRegularExpression('/data-testid="message-menu-button"[^>]*class="[^"]*(?=[^"]*min-h-6)(?=[^"]*min-w-6)/', $views['livewire/inbox/transcript.blade.php'], 'botão Ações da mensagem');
        $this->assertMatchesRegularExpression('/min-h-6[^\n]*aria-label="Renomear|aria-label="Renomear[^\n]*min-h-6/', $views['livewire/settings/sectors.blade.php'], 'botão Renomear');
        $this->assertMatchesRegularExpression('/id="alert-volume"[^\n]*class="[^"]*(?<![\w-])h-6(?![\w-])/', $views['pages/settings/⚡alerts.blade.php'], 'controle de volume');
    }

    /** Só `flux:button`: o `x-ui.button` próprio foi migrado e removido (regra em docs/ui-botoes.md). */
    public function test_nenhuma_view_usa_wire_confirm(): void
    {
        foreach ($this->views() as $path => $html) {
            $this->assertStringNotContainsString('wire:confirm', $html, "{$path}: use data-confirm (diálogo do app, resources/js/confirm-dialog.js), não o confirm() do navegador");
        }
    }

    public function test_todo_data_confirm_tem_rotulo_e_tom_conhecidos(): void
    {
        foreach ($this->views() as $path => $html) {
            $confirms = substr_count($html, 'data-confirm="');

            $this->assertSame($confirms, substr_count($html, 'data-confirm-label="'), "{$path}: todo data-confirm precisa de data-confirm-label");
            $this->assertSame($confirms, preg_match_all('/data-confirm-tone="(?:danger|primary)"/', $html), "{$path}: todo data-confirm precisa de data-confirm-tone danger|primary");
        }
    }

    public function test_nenhuma_view_usa_x_ui_button(): void
    {
        foreach ($this->views() as $path => $html) {
            $this->assertStringNotContainsString('<x-ui.button', $html, "{$path}: use <flux:button> (docs/ui-botoes.md)");
        }

        $this->assertFileDoesNotExist(dirname(__DIR__, 2).'/resources/views/components/ui/button.blade.php');
    }

    /**
     * Os `<button>` crus que sobram são gatilhos internos, não ações. Mudar esta lista é uma decisão (docs/ui-botoes.md), não um acaso.
     *
     * @return array<string, int> arquivo => quantidade de `<button` aprovada
     */
    private function botoesCrusAprovados(): array
    {
        return [
            'components/link-card.blade.php' => 1, // cartão inteiro clicável que abre o ticket (o flux:button não desenha cartão)
            'components/tag-picker.blade.php' => 1, // opção da lista selecionável do seletor de etiquetas
            'flux/navlist/group.blade.php' => 1, // sobrescrita do Flux
            'livewire/alerts/bell.blade.php' => 2, // gatilho do sino e item de alerta (lista selecionável)
            'livewire/automation/editor.blade.php' => 3, // foco no erro (Validar e Testar) e paleta de blocos (lista)
            'livewire/contacts/index.blade.php' => 1, // linha da lista de contatos
            'livewire/history/index.blade.php' => 1, // linha da lista do Histórico
            'livewire/inbox/audio-player.blade.php' => 2, // controles do player
            'livewire/inbox/inbox.blade.php' => 7, // cartões da fila (2), abas Responder/Nota (2), itens de resposta rápida (sugestão do / e lista do botão), Salvar nota (colorido do modo nota)
            'livewire/inbox/media.blade.php' => 2, // abrir a foto e fechar a prévia (sobre fundo escuro)
            'livewire/inbox/new-conversation.blade.php' => 1, // item da lista de contatos
            'livewire/inbox/transcript.blade.php' => 11, // citação clicável, menu da mensagem e seus itens, reações
            'livewire/settings/channel-card.blade.php' => 2, // seletor QR / código de pareamento
            'pages/settings/⚡two-factor-setup-modal.blade.php' => 1, // copiar dentro do campo
        ];
    }

    public function test_botoes_crus_restantes_sao_os_aprovados(): void
    {
        $atual = [];

        foreach ($this->views() as $path => $html) {
            $n = substr_count($html, '<button');

            if ($n > 0) {
                $atual[$path] = $n;
            }
        }

        ksort($atual);
        $aprovado = $this->botoesCrusAprovados();
        ksort($aprovado);

        $this->assertSame($aprovado, $atual, 'botão de ação é <flux:button>; <button> cru só para gatilho interno aprovado (docs/ui-botoes.md)');
    }
}
