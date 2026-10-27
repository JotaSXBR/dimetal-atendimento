<?php

namespace App\Livewire\Settings;

use App\Settings\WahaSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Http;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('WhatsApp')]
class WhatsApp extends Component
{
    public string $baseUrl = '';

    public string $sessionName = 'main';

    public string $apiKey = '';

    public string $webhookSecret = '';

    public ?string $errorMessage = null;

    public bool $saved = false;

    public function mount(): void
    {
        $this->baseUrl = WahaSettings::baseUrl();
        $this->sessionName = WahaSettings::sessionName() ?: 'main';
        $this->saved = WahaSettings::isConfigured();
    }

    public function save(): void
    {
        $this->errorMessage = null;
        $this->saved = false;

        $apiKey = $this->apiKey !== '' ? $this->apiKey : WahaSettings::apiKey();
        $webhookSecret = $this->webhookSecret !== '' ? $this->webhookSecret : WahaSettings::webhookSecret();

        $this->validate([
            'baseUrl' => ['required', 'url'],
            'sessionName' => ['required', 'string', 'max:255'],
            'apiKey' => [blank($apiKey) ? 'required' : 'nullable', 'string', 'max:255'],
            'webhookSecret' => [blank($webhookSecret) ? 'required' : 'nullable', 'string', 'max:255'],
        ]);

        $baseUrl = rtrim($this->baseUrl, '/');

        try {
            $response = Http::withHeaders(['X-Api-Key' => $apiKey])
                ->timeout(5)
                ->get($baseUrl.'/api/sessions');
        } catch (Throwable $exception) {
            $this->errorMessage = 'Não foi possível falar com o WAHA: '.$exception->getMessage();

            return;
        }

        if ($response->failed()) {
            $this->errorMessage = 'Não foi possível falar com o WAHA: HTTP '.$response->status();

            return;
        }

        WahaSettings::save($baseUrl, $this->sessionName, $apiKey, $webhookSecret);

        $this->apiKey = '';
        $this->webhookSecret = '';
        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.settings.whatsapp');
    }
}
