<?php

namespace App\Livewire\Setup;

use App\Actions\Channels\CreateChannel;
use App\Livewire\Concerns\ReceivesAvatar;
use App\Models\Channel;
use App\Models\Workflow;
use App\Services\Bot\DefaultWorkflow;
use App\Services\Bot\WorkflowPublisher;
use App\Services\Media\AvatarImage;
use App\Services\Setup\ApplySegment;
use App\Services\Setup\SetupState;
use App\Settings\CompanySettings;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Os três passos do admin recém-criado: empresa, segmento e canal. Concluir leva à fila. */
#[Title('Primeiros passos')]
class FirstSteps extends Component
{
    use ReceivesAvatar;

    public string $name = '';

    public string $sessionName = 'principal';

    public ?int $channelId = null;

    public string $step = 'empresa';

    public function mount(): void
    {
        $this->guard();

        $this->name = CompanySettings::name();
        $this->step = SetupState::step();
        $id = $this->step === 'canal' ? Channel::query()->orderBy('id')->value('id') : null;
        $this->channelId = is_numeric($id) ? (int) $id : null;
    }

    public function saveLogo(): void
    {
        $this->guard();

        CompanySettings::setLogo($this->avatarWebp(AvatarImage::SIZE_LOGO));
    }

    public function removeLogo(): void
    {
        $this->guard();

        CompanySettings::clearLogo();
    }

    public function saveCompany(): void
    {
        $this->guard();

        $this->validate(['name' => ['required', 'string', 'max:120']], [
            'name.required' => 'Informe o nome da empresa.',
            'name.max' => 'O nome pode ter até 120 caracteres.',
        ]);

        CompanySettings::save(['name' => $this->name]);
        SetupState::setStep('segmento');
        $this->step = 'segmento';
    }

    public function chooseSegment(string $segment): void
    {
        $this->guard();

        $user = auth()->user() ?? abort(403);

        try {
            app(ApplySegment::class)($segment, $user);
        } catch (InvalidArgumentException $e) {
            $this->addError('segment', $e->getMessage());

            return;
        }

        $this->step = SetupState::step();
    }

    public function createChannel(): void
    {
        $this->guard();

        $channel = app(CreateChannel::class)($this->sessionName, auth()->user());

        if (SetupState::segment() === ApplySegment::DISTRIBUIDORA) {
            $workflow = Workflow::query()->where('name', DefaultWorkflow::NAME)->first();

            if ($workflow !== null) {
                app(WorkflowPublisher::class)->activate($workflow, $channel);
            }
        }

        $this->channelId = $channel->id;
    }

    /** @param  array<string, mixed>  $payload */
    #[On('echo-private:inbox,.channel.status')]
    public function onChannelStatus(array $payload = []): void
    {
        if ($this->channelId === null || ($payload['channel_id'] ?? null) !== $this->channelId) {
            $this->skipRender();

            return;
        }

        if (Channel::query()->find($this->channelId)?->isConnected()) {
            $this->finish();
        }
    }

    public function skip(): void
    {
        $this->guard();

        $this->finish();
    }

    public function render(): View
    {
        return view('livewire.setup.first-steps', [
            'channel' => $this->channelId === null ? null : Channel::query()->find($this->channelId),
        ]);
    }

    private function finish(): void
    {
        SetupState::markConcluded();
        $this->redirectRoute('inbox');
    }

    private function guard(): void
    {
        abort_if(SetupState::concluded(), 404);
        $this->authorize('admin');
    }
}
