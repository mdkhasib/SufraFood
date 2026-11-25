<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A customer at a table pressed "Call waiter" / "Bill please" / … on the QR menu. */
class TableCall extends Model
{
    /** Buttons on the QR menu. */
    public const TYPES = ['WAITER' => ['🙋', 'Call waiter'], 'BILL' => ['💵', 'Bill please'], 'WATER' => ['💧', 'Water please'], 'OTHER' => ['💬', 'Something else']];

    /** Made by the system, not a customer button: a new QR order to serve, a bKash/Nagad payment to check. */
    public const SYSTEM = ['ORDER' => ['🍽', 'New QR order'], 'PAID' => ['📲', 'Paid online — check']];

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

    public function order()
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function answeredBy()
    {
        return $this->belongsTo(User::class, 'answered_by');
    }

    public function label(): string
    {
        return implode(' ', self::TYPES[$this->type] ?? self::SYSTEM[$this->type] ?? ['💬', $this->type]);
    }
}
