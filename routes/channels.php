<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('inbox', fn (User $user): bool => $user->active);

Broadcast::channel('users.{id}', fn (User $user, int $id): bool => $user->active && $user->id === $id);
