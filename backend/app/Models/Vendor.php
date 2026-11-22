<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vendor extends Model
{
    protected $guarded = [];

    public function bills()
    {
        return $this->hasMany(VendorBill::class);
    }
}
