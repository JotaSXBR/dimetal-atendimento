<?php

namespace App\Livewire\Settings;

use App\Enums\TagColor;
use App\Enums\TagScope;
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

    public string $scope = 'conversa';

    public string $color = 'ardosia';

    public function mount(): void
    {
        $this->authorize('supervisor');
    }

    public function newTag(?string $scope = null): void
    {
        $this->authorize('supervisor');

        $this->resetForm();
        $this->scope = TagScope::tryFrom((string) $scope)->value ?? TagScope::CONVERSA->value;
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
        $this->scope = $tag->scope->value;
        $this->color = $tag->color->value;
    }

    public function save(): void
    {
        $this->authorize('supervisor');

        $this->name = trim($this->name);

        $this->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('tags', 'name')->ignore($this->editingId)],
            'scope' => ['required', Rule::enum(TagScope::class)],
            'color' => ['required', Rule::enum(TagColor::class)],
        ], [
            'name.required' => 'Informe o nome da etiqueta.',
            'name.max' => 'O nome pode ter até 40 caracteres.',
            'name.unique' => 'Já existe uma etiqueta com esse nome.',
            'scope.enum' => 'Escolha se a etiqueta é do contato ou da conversa.',
            'color.enum' => 'Escolha uma das cores da lista.',
        ]);

        $tag = $this->editingId === null ? new Tag : Tag::query()->findOrFail($this->editingId);

        // Mudar o escopo de uma etiqueta já aplicada deixaria vínculos no lugar errado.
        if ($tag->exists && $tag->scope->value !== $this->scope && $this->inUse($tag)) {
            $this->addError('scope', 'O escopo não muda enquanto a etiqueta está em uso.');

            return;
        }

        $tag->fill(['name' => $this->name, 'scope' => $this->scope, 'color' => $this->color])->save();

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

    private function inUse(Tag $tag): bool
    {
        return $tag->tickets()->exists() || $tag->contacts()->exists();
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'scope', 'color');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        $tags = Tag::sortedByName(Tag::query()->withCount(['tickets', 'contacts'])->get());

        return view('livewire.settings.tags', [
            'contactTags' => $tags->where('scope', TagScope::CONTATO)->values(),
            'ticketTags' => $tags->where('scope', TagScope::CONVERSA)->values(),
            'palette' => TagColor::cases(),
        ]);
    }
}
