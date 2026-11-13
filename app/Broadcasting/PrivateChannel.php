<?php

namespace App\Broadcasting;

use App\Models\User;

class PrivateChannel
{
    /**
     * Authenticate the user's access to App.User.{id}.
     * The authenticated user may only join their own channel.
     */
    public function join( User $user, int|string $id ): bool
    {
        return (int) $user->id === (int) $id;
    }
}
