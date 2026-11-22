<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $guarded = [];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function permissionKeys(): array
    {
        return \Illuminate\Support\Facades\DB::table('role_permissions')->where('role_id', $this->id)->pluck('permission')->all();
    }
}
