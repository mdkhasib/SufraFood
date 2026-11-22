<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProxyGrant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
            'active' => 'boolean',
        ];
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function proxy()
    {
        return $this->belongsTo(User::class, 'proxy_user_id');
    }

    public function staff()
    {
        return $this->belongsToMany(User::class, 'proxy_grant_users');
    }
}
