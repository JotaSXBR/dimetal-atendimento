<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/** O seletor de emoji usa dados em português servidos pelo próprio app e nunca chama um host de terceiros. */
class EmojiDataTest extends TestCase
{
    public function test_os_dados_do_seletor_estao_no_app_e_em_portugues(): void
    {
        $path = dirname(__DIR__, 2).'/public/vendor/emoji/pt.json';

        $this->assertFileExists($path);

        $data = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($data);
        $this->assertGreaterThan(1500, count($data));

        $smile = array_values(array_filter($data, fn (array $e): bool => ($e['emoji'] ?? null) === '😀'));

        $this->assertSame('rosto risonho', $smile[0]['annotation'] ?? null);
    }

    public function test_o_seletor_e_o_compositor_nao_referenciam_host_externo(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['resources/js/emoji-picker.js', 'resources/views/livewire/inbox/inbox.blade.php'] as $file) {
            $source = (string) file_get_contents($root.'/'.$file);

            $this->assertDoesNotMatchRegularExpression('#https?://(?!www\.w3\.org)#', $source, "{$file} referencia host externo");
        }
    }

    public function test_o_seletor_aponta_para_os_dados_locais_e_carrega_sob_demanda(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/emoji-picker.js');

        $this->assertStringContainsString("'/vendor/emoji/pt.json'", $js);
        $this->assertStringContainsString("import('emoji-picker-element')", $js);
        $this->assertStringContainsString("this.\$dispatch('composer-focus')", $js);
    }
}
