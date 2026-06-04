<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Visitor;

class VisitorPolicy
{
    public function view(User $user, Visitor $visitor): bool
    {
        $visitor->loadMissing('site');

        return ! $user->isDeactivated()
            && $visitor->site?->supportsAgent($user) === true;
    }
}
