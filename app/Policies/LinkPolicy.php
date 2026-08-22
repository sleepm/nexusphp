<?php

namespace App\Policies;

use App\Models\Link;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class LinkPolicy extends BasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $this->can($user);
    }

    public function view(User $user, Link $link)
    {
        return $this->can($user);
    }

    public function create(User $user)
    {
        return $this->can($user);
    }

    public function update(User $user, Link $link)
    {
        return $this->can($user);
    }

    public function delete(User $user, Link $link)
    {
        return $this->can($user);
    }

    public function restore(User $user, Link $link)
    {
        //
    }

    public function forceDelete(User $user, Link $link)
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
