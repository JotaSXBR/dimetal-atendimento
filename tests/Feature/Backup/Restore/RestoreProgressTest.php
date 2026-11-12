<?php

namespace Tests\Feature\Backup\Restore;

use App\Services\Backup\Restore\RestoreProgress;
use Tests\TestCase;

class RestoreProgressTest extends TestCase
{
    public function test_get_sem_estado_devolve_idle(): void
    {
        $state = (new RestoreProgress)->get();

        $this->assertSame('idle', $state['status']);
        $this->assertSame([], $state['steps']);
        $this->assertFalse((new RestoreProgress)->isRunning());
    }

    public function test_comeca_ordena_e_termina_os_passos(): void
    {
        $progress = new RestoreProgress;

        $progress->begin('Restaurando o banco', ['inspect', 'safety', 'swap']);
        $this->assertTrue($progress->isRunning());
        $this->assertSame(['pending', 'pending', 'pending'], array_column($progress->get()['steps'], 'state'));
        $this->assertSame(['inspect', 'safety', 'swap'], array_column($progress->get()['steps'], 'key'));
        $this->assertSame('Conferindo o arquivo', $progress->get()['steps'][0]['label']);

        $progress->running('inspect');
        $this->assertSame(['running', 'pending', 'pending'], array_column($progress->get()['steps'], 'state'));

        $progress->done('inspect');
        $progress->running('safety');
        $progress->done('safety');
        $progress->running('swap');
        $progress->done('swap');
        $progress->finish('Restauração concluída.');

        $state = $progress->get();
        $this->assertSame('done', $state['status']);
        $this->assertSame(['done', 'done', 'done'], array_column($state['steps'], 'state'));
        $this->assertSame('Restauração concluída.', $state['message']);
        $this->assertSame('Restaurando o banco', $state['title']);
        $this->assertFalse($progress->isRunning());
    }

    public function test_falha_marca_o_passo_e_guarda_a_mensagem(): void
    {
        $progress = new RestoreProgress;
        $progress->begin('Restaurando o banco', ['inspect', 'safety']);
        $progress->running('inspect');
        $progress->done('inspect');
        $progress->running('safety');

        $progress->fail('Há um backup em andamento.');

        $state = $progress->get();
        $this->assertSame('failed', $state['status']);
        $this->assertSame(['done', 'failed'], array_column($state['steps'], 'state'));
        $this->assertSame('Há um backup em andamento.', $state['message']);
        $this->assertFalse($progress->isRunning());
    }

    public function test_um_estado_que_ficou_para_tras_expira_sozinho(): void
    {
        $progress = new RestoreProgress;
        $progress->begin('Restaurando o banco', ['inspect']);

        $this->travel(61)->minutes();

        $this->assertFalse($progress->isRunning());
        $this->assertSame('idle', $progress->get()['status']);
    }
}
