<?php

namespace App\Livewire\Settings;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Usuários')]
class Users extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $email = '';

    public string $role = 'atendente';

    public string $password = '';

    /** @var array<int, int> */
    public array $sectorIds = [];

    public bool $canTransfer = false;

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function newUser(): void
    {
        $this->authorize('viewAny', User::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $target = User::query()->findOrFail($id);
        $this->authorize('update', $target);

        $this->resetForm();
        $this->showForm = true;
        $this->editingId = $target->id;
        $this->name = $target->name;
        $this->email = $target->email;
        $this->role = $target->role->value;
        $this->canTransfer = $target->can_transfer;
        $this->sectorIds = $target->sectors()->get()->map(fn (Sector $sector): int => $sector->id)->all();
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function save(): void
    {
        $this->authorize('viewAny', User::class);

        $role = UserRole::tryFrom($this->role);
        $target = $this->editingId === null ? null : User::query()->findOrFail($this->editingId);

        if ($role === null) {
            $this->addError('role', 'Papel inválido.');

            return;
        }

        if ($target === null) {
            $this->authorize('create', [User::class, $role]);
        } else {
            $this->authorize('update', $target);

            if ($role !== $target->role) {
                $this->authorize('assignRole', [User::class, $role]);
            }
        }

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($target?->id)],
            'password' => [$target === null ? 'required' : 'nullable', 'string', Password::default()],
            'sectorIds' => ['array'],
            'sectorIds.*' => ['integer', Rule::exists('sectors', 'id')],
        ], [
            'name.required' => 'Informe o nome.',
            'email.required' => 'Informe o e-mail.',
            'email.email' => 'E-mail inválido.',
            'email.unique' => 'Já existe um usuário com esse e-mail.',
            'password.required' => 'Defina uma senha.',
            'password.min' => 'A senha precisa ter ao menos 8 caracteres.',
        ]);

        if ($target?->isLastActiveAdmin() && $role !== UserRole::ADMIN) {
            $this->addError('role', 'Este é o único administrador ativo. Promova outra pessoa antes de rebaixá-lo.');

            return;
        }

        $user = $target ?? new User;
        $user->forceFill([
            'name' => trim($this->name),
            'email' => trim($this->email),
            'role' => $role,
            'can_transfer' => $this->canTransfer,
        ]);

        if ($target === null) {
            $user->forceFill(['active' => true, 'email_verified_at' => now()]);
        }

        if ($this->password !== '') {
            $user->password = $this->password;
        }

        DB::transaction(function () use ($user): void {
            $user->save();
            $user->sectors()->sync($this->sectorIds);
        });

        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $target = User::query()->findOrFail($id);

        if ($target->active) {
            $this->authorize('deactivate', $target);
            $target->forceFill(['active' => false])->save();

            // O login já recusa quem está inativo; apagar as sessões derruba quem estava logado agora.
            DB::table('sessions')->where('user_id', $target->id)->delete();

            return;
        }

        $this->authorize('update', $target);
        $target->forceFill(['active' => true])->save();
    }

    /**
     * @return list<UserRole>
     */
    private function allowedRoles(): array
    {
        return array_values(array_filter(UserRole::cases(), fn (UserRole $role): bool => $this->user()->can('create', [User::class, $role])));
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    private function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'email', 'role', 'password', 'sectorIds', 'canTransfer');
        $this->resetErrorBag();
    }

    public function render(): View
    {
        return view('livewire.settings.users', [
            'users' => User::query()
                ->with('sectors')
                ->withCount(['assignedTickets as open_tickets_count' => fn (Builder $query) => $query->where('status', '<>', TicketStatus::ENCERRADO->value)])
                ->orderByDesc('active')
                ->orderBy('name')
                ->get(),
            'sectors' => Sector::query()->orderBy('menu_order')->get(),
            'roles' => $this->allowedRoles(),
        ]);
    }
}
