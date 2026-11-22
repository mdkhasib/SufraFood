<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RequisitionHistory extends Model
{
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function requisition()
    {
        return $this->belongsTo(Requisition::class);
    }
}
