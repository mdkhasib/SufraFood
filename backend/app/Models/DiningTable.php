<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DiningTable extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    protected static function booted(): void
    {
        // every new table gets its own QR code
        static::creating(fn (DiningTable $t) => $t->qr_token ??= Str::lower(Str::random(12)));
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /** The address the table's QR code opens. */
    public function qrUrl(): string
    {
        return rtrim(\App\Support\Brand::serverUrl(), '/').'/t/'.$this->qr_token;
    }
}
