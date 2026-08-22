<?php

namespace App\Policies;

use App\Models\Cheater;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class CheaterPolicy extends BasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $this->can($user);
    }

    public function view(User $user, Cheater $cheater)
    {
        return $this->can($user);
    }

    public function create(User $user)
    {
        return $this->can($user);
    }

    public function update(User $user, Cheater $cheater)
    {
        return $this->can($user);
    }

    public function delete(User $user, Cheater $cheater)
    {
        return $this->can($user);
    }

    public function deleteAny(User $user)
    {
        return $this->can($user);
    }

    public function restore(User $user, Cheater $cheater)
    {
        //
    }

    public function forceDelete(User $user, Cheater $cheater)
    {
        //
    }

    private function can(User $user)
    {
        if ($user->class >= User::CLASS_ADMINISTRATOR) {
            return true;
        }
        return false;
    }
}
