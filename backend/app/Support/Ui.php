<?php

namespace App\Support;

class Ui
{
    public static function badge(?string $status): string
    {
        $s = (string) $status;
        $c = match (true) {
            in_array($s, ['DONE', 'CLOSED', 'APPROVED', 'PAID', 'PRESENT', 'IN', 'ON', 'SETTLED', 'RECEIVED', 'ACCEPTED', 'DELIVERED', 'CONFIRMED', 'OUT', 'ACTIVE']) => 'bg-green-100 text-green-800',
            in_array($s, ['REJECTED', 'CANCELLED', 'ABSENT', 'NOT_ARRIVED', 'VOID', 'OFF_FEATURE', 'OVERDUE']) => 'bg-red-100 text-red-700',
            in_array($s, ['LATE', 'PENDING', 'PENDING_APPROVAL', 'SUBMITTED', 'PARTIALLY_ACCEPTED', 'PILOT', 'URGENT', 'PARTIAL', 'BOUGHT']) => 'bg-amber-100 text-amber-800',
            in_array($s, ['STARTED', 'IN_PROGRESS', 'DISPATCHED', 'SENT_TO_FULFILLER', 'ACCEPTED_BUYER', 'PICKED_UP', 'READY', 'OPEN', 'INQUIRY', 'ASSIGNED']) => 'bg-blue-100 text-blue-800',
            in_array($s, ['LEAVE', 'HOLIDAY', 'PROXY', 'REMOTE']) => 'bg-purple-100 text-purple-800',
            default => 'bg-slate-100 text-slate-700',
        };

        return '<span class="badge '.$c.'">'.e(str_replace('_', ' ', $s)).'</span>';
    }

    public static function tk($n, int $dec = 0): string
    {
        return '৳'.number_format((float) $n, $dec);
    }

    public static function q($n): string
    {
        $s = number_format((float) $n, 3, '.', '');

        return rtrim(rtrim($s, '0'), '.');
    }
}
