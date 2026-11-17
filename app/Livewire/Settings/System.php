<?php

namespace App\Livewire\Settings;

use App\Enums\AuditAction;
use App\Enums\UpdateStatus;
use App\Jobs\ApplyUpdateJob;
use App\Models\SystemUpdate;
use App\Services\Audit\Auditor;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Update\InstalledVersion;
use App\Services\Update\Preflight;
use App\Services\Update\ReleaseFeed;
use App\Services\Update\Version;
use App\Settings\UpdateSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Sistema')]
class System extends Component
{
    /** O que o admin digita para confirmar, como a restauração do banco. */
    public const CONFIRM_WORD = 'ATUALIZAR';

    /** Minutos abertos sem fechar: depois disso a tela deixa de prometer e manda para a recuperação. */
    private const STALE_MINUTES = 30;

    public string $panelUrl = '';

    /** Só existe entre a digitação e o salvar: o valor guardado nunca volta para a tela (nem no snapshot). */
    public string $tokenInput = '';

    public bool $confirming = false;

    public string $confirm = '';

    public bool $composeEdited = false;

    public ?string $problem = null;

    public ?string $connectionMessage = null;

    /** @var list<string> */
    public array $connectionErrors = [];

    public function mount(): void
    {
        $this->authorize('admin');
        $this->panelUrl = UpdateSettings::panelUrl() ?? '';
    }

    public function saveConnection(): void
    {
        $this->authorize('admin');
        $token = trim($this->tokenInput);

        try {
            $this->validate(['panelUrl' => ['nullable', 'url', 'max:255'], 'tokenInput' => ['nullable', 'string', 'max:500']], [
                'panelUrl.url' => 'Informe a URL completa do painel, com https://.',
            ]);
        } finally {
            // Sai do snapshot mesmo com erro de validação: senão o segredo digitado volta para a tela.
            $this->reset('tokenInput');
        }

        UpdateSettings::setPanelUrl(rtrim(trim($this->panelUrl), '/') ?: null);

        if ($token !== '') {
            UpdateSettings::setToken($token);
        }

        $this->reset('connectionErrors', 'connectionMessage');
        $this->panelUrl = UpdateSettings::panelUrl() ?? '';
    }

    public function removeToken(): void
    {
        $this->authorize('admin');
        UpdateSettings::setToken(null);
        $this->reset('tokenInput', 'connectionErrors', 'connectionMessage');
    }

    public function toggleCheck(): void
    {
        $this->authorize('admin');
        UpdateSettings::setCheckEnabled(! UpdateSettings::checkEnabled());
    }

    public function checkNow(ReleaseFeed $feed): void
    {
        $this->authorize('admin');
        $feed->refresh();
    }

    public function testConnection(Preflight $preflight): void
    {
        $this->authorize('admin');

        if (! $this->canUpdate()) {
            return;
        }

        $this->connectionErrors = $preflight->connection();
        $this->connectionMessage = $this->connectionErrors === [] ? 'Conexão com o Coolify funcionando.' : null;
    }

    /** Abre a confirmação; o aviso de compose editado faz rede, por isso só aqui e não no render. */
    public function askUpdate(Preflight $preflight): void
    {
        $this->authorize('admin');
        $this->reset('confirm', 'problem');
        $this->problem = $this->blocker();

        if ($this->problem !== null) {
            return;
        }

        $this->composeEdited = $preflight->composeEdited();
        $this->confirming = true;
    }

    public function cancelUpdate(): void
    {
        $this->authorize('admin');
        $this->reset('confirming', 'confirm', 'problem', 'composeEdited');
    }

    public function update(): void
    {
        $this->authorize('admin');
        $target = $this->target();

        $this->problem = $this->blocker() ?? match (true) {
            $this->confirm !== self::CONFIRM_WORD => 'Digite '.self::CONFIRM_WORD.' para confirmar.',
            app(RestoreProgress::class)->isRunning() => 'Há uma restauração em andamento; espere ela terminar para atualizar.',
            app(ManualBackupState::class)->isRunning() => 'Há um backup em andamento; espere ele terminar para atualizar.',
            default => null,
        };

        if ($this->problem !== null) {
            return;
        }

        $update = SystemUpdate::start(Auth::user(), app(InstalledVersion::class)->current(), (string) $target);

        if ($update === null) {
            $this->problem = 'Já há uma atualização em andamento.';

            return;
        }

        Auditor::record(AuditAction::UpdateRequested, Auth::user(), (string) $target);
        ApplyUpdateJob::dispatch($update->id);
        $this->cancelUpdate();
    }

    /** Por que esta instalação não pode atualizar agora; a tela esconde o botão, mas a ação chamada direto também recusa. */
    private function blocker(): ?string
    {
        return match (true) {
            $this->resourceUuid() === null => 'Esta instalação não é um Service do Coolify; atualize pelo painel.',
            ! $this->canUpdate() || $this->target() === null => 'Não há versão nova para instalar.',
            UpdateSettings::token() === null => 'Salve o token da API do Coolify antes de atualizar.',
            default => null,
        };
    }

    /** Só uma Release instalada, num Service do Coolify, tem o que atualizar; o `sha-…` do beta faria o pré-voo gravar lixo no Coolify. */
    private function canUpdate(): bool
    {
        return app(InstalledVersion::class)->release() !== null && $this->resourceUuid() !== null;
    }

    private function resourceUuid(): ?string
    {
        $uuid = config('fluxie.resource_uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    /** A versão disponível, se for maior que a instalada. */
    private function target(): ?Version
    {
        $installed = app(InstalledVersion::class)->release();
        $latest = UpdateSettings::latest();
        $version = $latest === null ? null : Version::parse($latest['tag']);

        return $installed !== null && $version !== null && $version->greaterThan($installed) ? $version : null;
    }

    public function render(): View
    {
        $current = app(InstalledVersion::class)->current();
        $timezone = config()->string('app.display_timezone');
        $open = SystemUpdate::open();
        $last = $open ?? SystemUpdate::query()->latest('id')->first();
        $lastCheck = UpdateSettings::lastCheck();
        $saved = UpdateSettings::tokenSavedAt();
        $steps = [UpdateStatus::Conferindo, UpdateStatus::Backup, UpdateStatus::Aplicando, UpdateStatus::Reiniciando];

        return view('livewire.settings.system', [
            'installed' => app(InstalledVersion::class)->release() === null ? null : $current,
            'sha' => substr((string) preg_replace('/^sha-/', '', $current), 0, 7),
            'hasService' => $this->resourceUuid() !== null,
            'canUpdate' => $this->canUpdate(),
            'target' => $this->target(),
            'latest' => UpdateSettings::latest(),
            'checkEnabled' => UpdateSettings::checkEnabled(),
            'lastCheck' => $lastCheck === null ? null : ['at' => $lastCheck['at']->timezone($timezone)->format('d/m/Y H:i'), 'ok' => $lastCheck['ok'], 'error' => $lastCheck['error']],
            'tokenSaved' => UpdateSettings::token() !== null,
            'tokenSavedAt' => $saved?->timezone($timezone)->format('d/m'),
            'confirmWord' => self::CONFIRM_WORD,
            'last' => $last,
            'open' => $open !== null,
            'stale' => $open !== null && $open->started_at !== null && $open->started_at->lt(now()->subMinutes(self::STALE_MINUTES)),
            'steps' => array_map(fn (UpdateStatus $step): array => [
                'label' => $step->label(),
                'state' => $last === null ? 'pending' : $this->stepState($step, $steps, $last->status),
            ], $steps),
        ]);
    }

    /**
     * @param  list<UpdateStatus>  $steps
     */
    private function stepState(UpdateStatus $step, array $steps, UpdateStatus $status): string
    {
        $at = array_search($status, $steps, true);

        return match (true) {
            $at === false => 'pending', // fechada: a tela não mostra os passos, só o resultado escrito
            array_search($step, $steps, true) < $at => 'done',
            $step === $status => 'running',
            default => 'pending',
        };
    }
}
