<?php

namespace App\Policies;

use App\Models\Advertisement;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class AdvertisementPolicy extends BasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $this->can($user);
    }

    public function view(User $user, Advertisement $advertisement)
    {
        return $this->can($user);
    }

    public function create(User $user)
    {
        return $this->can($user);
    }

    public function update(User $user, Advertisement $advertisement)
    {
        return $this->can($user);
    }

    public function delete(User $user, Advertisement $advertisement)
    {
        return $this->can($user);
    }

    public function restore(User $user, Advertisement $advertisement)
    {
        //
    }

    public function forceDelete(User $user, Advertisement $advertisement)
    {
        //
    }

    private function can(User $user)
    {
        if ($user->class >= User::CLASS_MODERATOR) {
            return true;
        }
        return false;
    }
}
