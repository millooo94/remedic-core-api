<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class InternalNotificationRecipientResolver
{
    /** @return Collection<int, User> */
    public function forPermission(string $permission): Collection
    {
        return User::query()
            ->permission($permission)
            ->where('is_active', true)
            ->get()
            ->unique('id')
            ->values();
    }
}
