<?php

namespace App\Policies;

use App\Models\Ban;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BanPolicy extends BasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $this->can($user);
    }

    public function view(User $user, Ban $ban)
    {
        return $this->can($user);
    }

    public function create(User $user)
    {
        return $this->can($user);
    }

    public function update(User $user, Ban $ban)
    {
        return $this->can($user);
    }

    public function delete(User $user, Ban $ban)
    {
        return $this->can($user);
    }

    public function deleteAny(User $user)
    {
        return $this->can($user);
    }

    public function restore(User $user, Ban $ban)
    {
        //
    }

    public function forceDelete(User $user, Ban $ban)
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
