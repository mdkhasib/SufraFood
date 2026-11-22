<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Task extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
            'proof_required' => 'boolean',
            'escalated' => 'boolean',
            'notify_on_start' => 'boolean',
            'notify_on_done' => 'boolean',
            'pre_reminded_at' => 'datetime',
            'last_reminded_at' => 'datetime',
        ];
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function assigner()
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    /** Effective repeat-reminder interval while overdue (explicit, else by priority). */
    public function repeatEvery(): ?int
    {
        if ($this->remind_every_minutes) {
            return (int) $this->remind_every_minutes;
        }

        return match ($this->priority) {
            'URGENT' => (int) \App\Support\Features::setting('tasks.urgent_remind_every', 15) ?: null,
            'HIGH' => (int) \App\Support\Features::setting('tasks.high_remind_every', 30) ?: null,
            default => null,
        };
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function template()
    {
        return $this->belongsTo(TaskTemplate::class, 'template_id');
    }

    public function isOverdue(): bool
    {
        return ! in_array($this->status, ['DONE', 'CANCELLED']) && $this->due_at && $this->due_at->isPast();
    }

    public function displayTitle(?string $lang = null): string
    {
        $lang = $lang ?? app()->getLocale();
        return ($lang === 'bn' && $this->title_bn) ? $this->title_bn : $this->title;
    }
}
