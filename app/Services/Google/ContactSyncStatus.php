<?php

namespace App\Services\Google;

use App\Models\Contact;

/** O estado do envio de um contato ao Google, em texto para a ficha. */
class ContactSyncStatus
{
    private const ERRORS = [
        'unauthorized' => 'acesso recusado pelo Google; reconecte a conta',
        'forbidden' => 'acesso recusado pelo Google; reconecte a conta',
        'invalid_grant' => 'a autorização do Google expirou; reconecte a conta',
        'token_refresh_failed' => 'não foi possível renovar o acesso ao Google; tentaremos de novo',
        'api_disabled' => 'a People API está desligada no projeto do Google',
        'rate_limited' => 'limite de uso do Google; tentaremos de novo',
        'conflict' => 'o contato mudou no Google durante o envio',
        'unavailable' => 'o Google está indisponível; tentaremos de novo',
        'bad_request' => 'o Google recusou os dados deste contato',
        'invalid_response' => 'resposta inesperada do Google',
    ];

    public function __construct(private readonly GoogleAccount $account, private readonly ContactToPerson $mapper) {}

    /**
     * @return array{text: string, error: bool}
     */
    public function describe(Contact $contact): array
    {
        if ($contact->google_error !== null) {
            return ['text' => 'Erro: '.(self::ERRORS[$contact->google_error] ?? $contact->google_error), 'error' => true];
        }

        $text = match (true) {
            ! $this->account->isConnected() => 'Google não conectado',
            $contact->edited_at === null => 'Será enviado ao Google depois da primeira edição',
            ! $this->mapper->isEligible($contact) => 'Sem telefone nem e-mail para enviar ao Google',
            $this->isInSync($contact) => 'Sincronizado em '.$contact->google_synced_at?->timezone(config()->string('app.timezone'))->format('d/m/Y H:i'),
            default => 'Pendente',
        };

        return ['text' => $text, 'error' => false];
    }

    private function isInSync(Contact $contact): bool
    {
        return $contact->google_synced_at !== null && $contact->updated_at !== null && ! $contact->updated_at->gt($contact->google_synced_at);
    }
}
