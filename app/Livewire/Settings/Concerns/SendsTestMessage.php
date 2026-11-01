<?php

namespace App\Livewire\Settings\Concerns;

use App\Services\Waha\WahaClient;
use Throwable;

/**
 * O texto de teste do cartão do canal: o telefone e a mensagem que o dono digita, e o envio pelo WAHA.
 * Usa `channel`, `fail()` e `onlyDigits()` do componente.
 */
trait SendsTestMessage
{
    public string $testPhone = '';

    public string $testText = 'Teste do painel de atendimento';

    public ?string $sentMessage = null;

    public function sendTest(): void
    {
        $this->reset('errorMessage', 'sentMessage');

        $this->validate([
            'testPhone' => ['required', 'string'],
            'testText' => ['required', 'string', 'max:4096'],
        ]);

        $phone = $this->onlyDigits($this->testPhone);

        if (! preg_match('/^\d{12,13}$/', $phone)) {
            $this->addError('testPhone', 'Informe o telefone com DDI e DDD, só números.');

            return;
        }

        try {
            $response = (new WahaClient($this->channel))->sendText($this->channel->normalizeChatId($phone), $this->testText);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->leaveOffline();

        $id = $response['id'] ?? null;
        $this->sentMessage = 'Enviado: '.(is_string($id) ? $id : 'sem id');
    }

    /**
     * Depois de enviar, o WAHA precisa voltar a ficar offline para o celular
     * do atendente receber notificação — presença online segura o push.
     */
    private function leaveOffline(): void
    {
        try {
            (new WahaClient($this->channel))->setPresence('offline');
        } catch (Throwable) {
            // Presença é best-effort: a mensagem já foi enviada.
        }
    }
}
