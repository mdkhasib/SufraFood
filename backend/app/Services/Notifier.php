<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;

class Notifier
{
    /** @param User|int|iterable $users */
    public static function send($users, string $title, ?string $body = null, string $type = 'info', ?string $link = null): void
    {
        $ids = [];
        if ($users instanceof User) $ids = [$users->id];
        elseif (is_int($users)) $ids = [$users];
        else foreach ($users as $u) $ids[] = $u instanceof User ? $u->id : (int) $u;
        foreach (array_unique(array_filter($ids)) as $id) {
            AppNotification::create(['user_id' => $id, 'title' => $title, 'body' => $body, 'type' => $type, 'link' => $link]);
        }
    }

    /** Users at a location holding a permission. */
    public static function usersWithPerm(string $perm, ?int $locationId = null)
    {
        $q = User::where('status', 'ACTIVE')->with('role');
        if ($locationId) $q->where('location_id', $locationId);
        return $q->get()->filter(fn (User $u) => ! $u->is_super_admin && $u->hasPerm($perm));
    }
}
