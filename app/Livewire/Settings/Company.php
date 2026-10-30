<?php

namespace App\Livewire\Settings;

use App\Settings\CompanySettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Empresa')]
class Company extends Component
{
    public string $name = '';

    public bool $signatureEnabled = true;

    public string $signatureTemplate = '';

    public string $signaturePosition = 'inicio';

    public int $slaMinutes = 10;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorize('admin');

        $this->name = CompanySettings::name();
        $this->signatureEnabled = CompanySettings::signatureEnabled();
        $this->signatureTemplate = CompanySettings::signatureTemplate();
        $this->signaturePosition = CompanySettings::signaturePosition();
        $this->slaMinutes = CompanySettings::slaMinutes();
    }

    public function save(): void
    {
        $this->authorize('admin');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'signatureEnabled' => ['boolean'],
            'signatureTemplate' => ['required', 'string', 'max:300'],
            'signaturePosition' => ['required', 'in:'.implode(',', CompanySettings::POSITIONS)],
            'slaMinutes' => ['required', 'integer', 'between:1,1440'],
        ], [
            'name.required' => 'Informe o nome da empresa.',
            'name.max' => 'O nome pode ter até 120 caracteres.',
            'signatureTemplate.required' => 'Escreva o modelo da assinatura ou desligue a assinatura.',
            'signatureTemplate.max' => 'O modelo pode ter até 300 caracteres.',
            'slaMinutes.between' => 'O SLA vai de 1 a 1440 minutos.',
        ]);

        CompanySettings::save([
            'name' => $this->name,
            'signatureEnabled' => $this->signatureEnabled,
            'signatureTemplate' => $this->signatureTemplate,
            'signaturePosition' => $this->signaturePosition,
            'slaMinutes' => $this->slaMinutes,
        ]);
        $this->saved = true;
    }

    public function render(): View
    {
        return view('livewire.settings.company');
    }
}
