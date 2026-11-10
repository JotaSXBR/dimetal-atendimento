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
            $this->assertDoesNotMatchRegularExpression('/<flux:badge[^>]*\scolor=/', $html, "{$path}: flux:badge com cor da paleta; use <x-status-badge>");

            if ($path === 'components/status-badge.blade.php' || in_array($path, $contadores, true)) {
                continue;
            }

            foreach (explode("\n", $html) as $n => $line) {
                $this->assertDoesNotMatchRegularExpression('/rounded-full[^"\']*\spx-[^"\']*(bg|text)-(ok|warn|danger|bot)\b|(bg|text)-(ok|warn|danger|bot)\b[^"\']*\srounded-full[^"\']*\spx-/', $line, $path.':'.($n + 1).': pílula de estado feita à mão; use <x-status-badge>');
            }
        }
    }

    /** Só `flux:button`: o `x-ui.button` próprio foi migrado e removido (regra em docs/ui-botoes.md). */
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
            'flux/navlist/group.blade.php' => 1, // sobrescrita do Flux
            'livewire/alerts/bell.blade.php' => 2, // gatilho do sino e item de alerta (lista selecionável)
            'livewire/automation/editor.blade.php' => 2, // foco no erro e paleta de blocos (lista)
            'livewire/contacts/contact-card.blade.php' => 1, // protocolo que abre o ticket (texto-link)
            'livewire/contacts/index.blade.php' => 1, // linha da lista de contatos
            'livewire/history/index.blade.php' => 1, // linha da lista do Histórico
            'livewire/inbox/audio-player.blade.php' => 2, // controles do player
            'livewire/inbox/inbox.blade.php' => 6, // cartões da fila (2), abas Responder/Nota (2), item de resposta rápida, Salvar nota (colorido do modo nota)
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
