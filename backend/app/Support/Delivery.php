<?php

namespace App\Support;

use App\Models\Location;
use App\Models\User;

/** Home delivery switch and per-branch rider rate (Company settings → Home delivery). */
class Delivery
{
    public const STEPS = ['COOKING' => 'In kitchen', 'READY' => 'Ready to collect', 'PICKED_UP' => 'On the way', 'DELIVERED' => 'Delivered', 'CANCELLED' => 'Cancelled'];

    public static function on(): bool
    {
        return (bool) (int) Features::setting('delivery.enabled', 1) && Features::enabled('pos.core', null, true);
    }

    /** ৳ the rider earns per delivery at this branch. */
    public static function rate(?Location $l): float
    {
        return $l && $l->delivery_rate !== null ? (float) $l->delivery_rate : (float) Features::setting('delivery.rate_default', 50);
    }

    /** Can this person see the home delivery board? */
    public static function canView(User $u): bool
    {
        return static::on() && ($u->hasAnyPerm(['delivery.manage', 'delivery.order', 'pos.sell']));
    }

    /** Can this person run deliveries (assign riders, receive cash) at a branch? */
    public static function canManage(User $u, int $locationId): bool
    {
        if (! static::on()) {
            return false;
        }
        if ($u->hasPerm('delivery.manage') && ($u->isGroupWide() || $u->location_id === $locationId)) {
            return true;
        }

        return false;
    }

    /** Cash from a rider can be taken by the manager or the branch cashier. */
    public static function canReceiveCash(User $u, int $locationId): bool
    {
        return static::canManage($u, $locationId) || (static::on() && $u->hasPerm('pos.sell') && $u->location_id === $locationId);
    }

    /** Who can be given a delivery at a branch: staff marked “can deliver” (or everyone at the branch, per the setting). */
    public static function riders(int $locationId)
    {
        $q = User::where('status', 'ACTIVE')->where('is_super_admin', false)->where('location_id', $locationId);
        if (Features::setting('delivery.riders', 'MARKED') === 'MARKED') {
            $q->where('can_deliver', true);
        }

        return $q->orderBy('name')->get();
    }
}
