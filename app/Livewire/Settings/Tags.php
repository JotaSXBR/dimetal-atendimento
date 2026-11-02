<?php

namespace App\Livewire\Settings;

use App\Models\Tag;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Etiquetas')]
class Tags extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $color = '#2F855A';

    public function mount(): void
    {
        $this->authorize('supervisor');
    }

    public function newTag(): void
    {
        $this->authorize('supervisor');

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $this->authorize('supervisor');

        $tag = Tag::query()->findOrFail($id);
        $this->resetForm();
        $this->showForm = true;
        $this->editingId = $tag->id;
        $this->name = $tag->name;
        $this->color = $tag->color;
    }

    public function save(): void
    {
        $this->authorize('supervisor');

        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('tags', 'name')->ignore($this->editingId)],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => 'Informe o nome da etiqueta.',
            'name.max' => 'O nome pode ter até 40 caracteres.',
            'name.unique' => 'Já existe uma etiqueta com esse nome.',
            'color.regex' => 'Use uma cor no formato #RRGGBB.',
        ]);

        $tag = $this->editingId === null ? new Tag : Tag::query()->findOrFail($this->editingId);
        $tag->fill(['name' => $this->name, 'color' => $this->color])->save();

        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $this->authorize('supervisor');

        Tag::query()->whereKey($id)->delete();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name');
        $this->color = '#2F855A';
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.settings.tags', ['tags' => Tag::query()->withCount('tickets')->orderBy('name')->get()]);
    }
}
