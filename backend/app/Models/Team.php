<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function location() { return $this->belongsTo(Location::class); }
    public function department() { return $this->belongsTo(Department::class); }
    public function lead() { return $this->belongsTo(User::class, 'lead_id'); }
    public function parent() { return $this->belongsTo(Team::class, 'parent_id'); }
    public function children() { return $this->hasMany(Team::class, 'parent_id'); }
    public function members() { return $this->hasMany(User::class, 'team_id'); }

    public function displayName(?string $lang = null): string
    {
        $lang = $lang ?? app()->getLocale();
        return ($lang === 'bn' && $this->name_bn) ? $this->name_bn : $this->name;
    }

    /** Member ids (active), lead excluded. */
    public function memberIds(): array
    {
        return $this->members()->where('status', 'ACTIVE')->where('id', '!=', (int) $this->lead_id)->pluck('id')->all();
    }
}
