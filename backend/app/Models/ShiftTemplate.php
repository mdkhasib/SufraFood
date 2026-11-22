<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftTemplate extends Model
{
    protected $guarded = [];

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function label(): string
    {
        $s = substr($this->start_time, 0, 5).'-'.substr($this->end_time, 0, 5);
        if ($this->start_time2) $s .= ' + '.substr($this->start_time2, 0, 5).'-'.substr($this->end_time2, 0, 5);
        return $this->name.' ('.$s.')';
    }

    public function plannedMinutes(): int
    {
        $m = fn ($a, $b) => (int) ((strtotime($b) - strtotime($a)) / 60 + (strtotime($b) < strtotime($a) ? 1440 : 0));
        $total = $m($this->start_time, $this->end_time);
        if ($this->start_time2) $total += $m($this->start_time2, $this->end_time2);
        return max(0, $total - (int) $this->break_minutes);
    }
}
