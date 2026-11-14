<?php

namespace Tests\Feature\Components;

use Tests\TestCase;

/** O diálogo de confirmação do app (no lugar do `confirm()` do navegador). */
class ConfirmDialogTest extends TestCase
{
    public function test_renderiza_o_dialogo_com_os_dois_tons_e_o_cancelar(): void
    {
        $this->blade('<x-confirm-dialog />')
            ->assertSee('<dialog id="confirm-dialog"', false)
            ->assertSee('aria-labelledby="confirm-dialog-title"', false)
            ->assertSee('aria-describedby="confirm-dialog-text"', false)
            ->assertSee('method="dialog"', false)
            ->assertSee('data-confirm-ok="primary"', false)
            ->assertSee('data-confirm-ok="danger"', false)
            ->assertSee('value="cancel"', false)
            ->assertSee('Cancelar');
    }
}
