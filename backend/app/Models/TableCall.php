<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer at a table pressed "Call waiter" / "Bill please" / … on the QR menu. */
class TableCall extends Model
{
    public const TYPES = ['WAITER' => ['🙋', 'Call waiter'], 'BILL' => ['💵', 'Bill please'], 'WATER' => ['💧', 'Water please'], 'OTHER' => ['💬', 'Something else']];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['answered_at' => 'datetime', 'done_at' => 'datetime'];
    }

    public function table()
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function answeredBy()
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function label(): string
    {
        return implode(' ', self::TYPES[$this->type] ?? ['💬', $this->type]);
    }
}
