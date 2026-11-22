<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class Numbering
{
    /** e.g. FO-261120-014 */
    public static function next(string $prefix, string $table, string $column = 'number'): string
    {
        $base = $prefix.'-'.now()->format('ymd').'-';
        $last = DB::table($table)->where($column, 'like', $base.'%')->orderByDesc($column)->value($column);
        $n = $last ? ((int) substr($last, strlen($base))) + 1 : 1;
        return $base.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
