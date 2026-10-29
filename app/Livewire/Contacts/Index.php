<?php

namespace App\Livewire\Contacts;

use App\Actions\Contacts\CreateContact;
use App\Models\Contact;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Contatos')]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $selectedId = null;

    public bool $showNew = false;

    public string $newPhone = '';

    public string $newName = '';

    public string $notice = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function select(int $id): void
    {
        $this->selectedId = Contact::query()->findOrFail($id)->id;
        $this->notice = '';
    }

    public function createContact(CreateContact $createContact): void
    {
        $this->validate([
            'newName' => ['required', 'string', 'max:120'],
            'newPhone' => ['required', 'string', 'max:32'],
        ], [
            'newName.required' => 'Informe o nome.',
            'newPhone.required' => 'Informe o telefone.',
        ]);

        try {
            $result = $createContact($this->newPhone, $this->newName);
        } catch (ValidationException $e) {
            $this->addError('newPhone', $e->errors()['phone'][0] ?? 'Telefone inválido.');

            return;
        }

        $this->selectedId = $result['contact']->id;
        $this->notice = $result['created'] ? '' : 'Este número já estava cadastrado: abri o contato existente.';
        $this->reset('newPhone', 'newName', 'showNew');
    }

    public function render(): View
    {
        return view('livewire.contacts.index', [
            'contacts' => Contact::query()
                ->search($this->search)
                ->orderByRaw('lower(name) nulls last')
                ->orderBy('id')
                ->paginate(self::PER_PAGE),
        ]);
    }
}
