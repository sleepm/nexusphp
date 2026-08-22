<?php

namespace App\Policies;

use App\Models\AllowedEmail;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AllowedEmailPolicy extends BasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $this->can($user);
    }

    public function view(User $user, AllowedEmail $allowedEmail)
    {
        return $this->can($user);
    }

    public function create(User $user)
    {
        return $this->can($user);
    }

    public function update(User $user, AllowedEmail $allowedEmail)
    {
        return $this->can($user);
    }

    public function delete(User $user, AllowedEmail $allowedEmail)
    {
        return $this->can($user);
    }

    public function deleteAny(User $user)
    {
        return $this->can($user);
    }

    public function restore(User $user, AllowedEmail $allowedEmail)
    {
        //
    }

    public function forceDelete(User $user, AllowedEmail $allowedEmail)
    {
        //
    }

    private function can(User $user)
    {
        if ($user->class >= User::CLASS_SYSOP) {
            return true;
        }
        return false;
    }
}