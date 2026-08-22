<?php

namespace App\Policies;

use App\Models\BannedEmail;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class BannedEmailPolicy extends BasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $this->can($user);
    }

    public function view(User $user, BannedEmail $bannedEmail)
    {
        return $this->can($user);
    }

    public function create(User $user)
    {
        return $this->can($user);
    }

    public function update(User $user, BannedEmail $bannedEmail)
    {
        return $this->can($user);
    }

    public function delete(User $user, BannedEmail $bannedEmail)
    {
        return $this->can($user);
    }

    public function deleteAny(User $user)
    {
        return $this->can($user);
    }

    public function restore(User $user, BannedEmail $bannedEmail)
    {
        //
    }

    public function forceDelete(User $user, BannedEmail $bannedEmail)
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