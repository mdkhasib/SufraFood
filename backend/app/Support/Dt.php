<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class Dt
{
    /** Parse client input (local or ISO-with-offset) into app-timezone time. */
    public static function parse($value): Carbon
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'));
    }
}
