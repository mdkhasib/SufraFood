<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Requisition extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'needed_by' => 'datetime',
            'accepted_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
            'estimated_amount' => 'float',
        ];
    }

    public function lines()
    {
        return $this->hasMany(RequisitionLine::class);
    }

    public function histories()
    {
        return $this->hasMany(RequisitionHistory::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function fromLocation()
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function toLocation()
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function children()
    {
        return $this->belongsToMany(Requisition::class, 'requisition_links', 'parent_id', 'child_id');
    }

    public function parents()
    {
        return $this->belongsToMany(Requisition::class, 'requisition_links', 'child_id', 'parent_id');
    }

    public function purchaseAssignment()
    {
        return $this->hasOne(PurchaseAssignment::class);
    }

    public function dispatch()
    {
        return $this->hasOne(Dispatch::class);
    }

    public function approvalSteps()
    {
        return $this->morphMany(ApprovalStep::class, 'approvable');
    }

    public function typeLabel(): string
    {
        return config('groupops.requisition_types.'.$this->type.'.0', $this->type);
    }
}
