<?php

namespace App\Livewire\Settings;

use App\Models\QuickReply;
use App\Models\Sector;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Respostas rápidas')]
class QuickReplies extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $shortcut = '';

    public string $body = '';

    public ?int $sectorId = null;

    public function mount(): void
    {
        $this->authorize('supervisor');
    }

    public function newReply(): void
    {
        $this->authorize('supervisor');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('supervisor');

        $reply = QuickReply::query()->findOrFail($id);
        $this->resetForm();
        $this->showForm = true;
        $this->editingId = $reply->id;
        $this->shortcut = $reply->shortcut;
        $this->body = $reply->body;
        $this->sectorId = $reply->sector_id;
    }

    public function save(): void
    {
        $this->authorize('supervisor');

        // "/Preco" digitado vale `preco`: a barra é o gatilho, não faz parte do atalho.
        $this->shortcut = strtolower(ltrim(trim($this->shortcut), '/'));

        $this->validate([
            'shortcut' => ['required', 'regex:/^[a-z0-9_-]{1,30}$/', Rule::unique('quick_replies', 'shortcut')->ignore($this->editingId)],
            'body' => ['required', 'string', 'max:1000'],
            'sectorId' => ['nullable', 'integer', Rule::exists('sectors', 'id')],
        ], [
            'shortcut.required' => 'Informe o atalho.',
            'shortcut.regex' => 'Use só letras sem acento, números, hífen e sublinhado (até 30).',
            'shortcut.unique' => 'Já existe uma resposta com esse atalho.',
            'body.required' => 'Escreva o texto da resposta.',
            'body.max' => 'O texto pode ter até 1000 caracteres.',
        ]);

        $reply = $this->editingId === null ? new QuickReply : QuickReply::query()->findOrFail($this->editingId);
        $reply->fill(['shortcut' => $this->shortcut, 'body' => trim($this->body), 'sector_id' => $this->sectorId])->save();

        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $this->authorize('supervisor');

        QuickReply::query()->whereKey($id)->delete();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'shortcut', 'body', 'sectorId');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.settings.quick-replies', [
            'replies' => QuickReply::query()->with('sector')->orderBy('shortcut')->get(),
            'sectors' => Sector::query()->orderBy('menu_order')->get(),
        ]);
    }
}
