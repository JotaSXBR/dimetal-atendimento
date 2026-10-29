<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/**
 * Quem administra quem: o admin administra todos; o supervisor, só atendentes. Ninguém desativa a si mesmo nem o último
 * admin ativo. A tela esconde o que a Policy nega, mas é o servidor que decide, a cada ação.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->active && $actor->canSeeAllSectors();
    }

    public function create(User $actor, UserRole $role): bool
    {
        return $this->manages($actor, $role);
    }

    /** Dar ou trocar o papel de alguém. */
    public function assignRole(User $actor, UserRole $role): bool
    {
        return $this->manages($actor, $role);
    }

    public function update(User $actor, User $target): bool
    {
        return $this->manages($actor, $target->role);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $actor->id !== $target->id
            && $this->update($actor, $target)
            && ! $target->isLastActiveAdmin();
    }

    private function manages(User $actor, UserRole $role): bool
    {
        return $actor->active
            && ($actor->isAdmin() || ($actor->role === UserRole::SUPERVISOR && $role === UserRole::ATENDENTE));
    }
}
