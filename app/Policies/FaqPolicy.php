<?php

namespace App\Policies;

use App\Models\Faq;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class FaqPolicy extends BasePolicy
{
    use HandlesAuthorization;

    public function viewAny(User $user)
    {
        return $this->can($user);
    }

    public function view(User $user, Faq $faq)
    {
        return $this->can($user);
    }

    public function create(User $user)
    {
        return $this->can($user);
    }

    public function update(User $user, Faq $faq)
    {
        return $this->can($user);
    }

    public function delete(User $user, Faq $faq)
    {
        return $this->can($user);
    }

    public function restore(User $user, Faq $faq)
    {
        //
    }

    public function forceDelete(User $user, Faq $faq)
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
