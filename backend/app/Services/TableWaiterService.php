<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\PosOrder;
use App\Models\TableCall;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Waiters for tables: the branch manager gives each table its waiter(s) (one waiter many tables, many
 * waiters one table). When a customer calls from the QR menu, orders, or pays online, each waiter of
 * that table gets a task. The first waiter who finishes it closes the call for everyone.
 */
class TableWaiterService
{
    /** Staff who can be put on tables at a branch. */
    public static function candidates(int $locationId): Collection
    {
        return User::with('role')->where('location_id', $locationId)->where('status', 'ACTIVE')->where('is_super_admin', false)
            ->orderBy('name')->get()
            ->reject(fn (User $u) => in_array($u->role?->key, ['branch_manager', 'head_chef', 'cashier', 'delivery_rider'], true))->values();
    }

    public static function waiters(DiningTable $t): Collection
    {
        return $t->waiters()->where('status', 'ACTIVE')->get();
    }

    /** Save the table → waiters matrix of one branch: $map[table_id] = [user_id, …]. */
    public static function save(int $locationId, array $map, User $by): int
    {
        $allowed = static::candidates($locationId)->pluck('id')->all();
        $n = 0;
        foreach (DiningTable::where('location_id', $locationId)->get() as $t) {
            $ids = array_values(array_intersect(array_map('intval', (array) ($map[$t->id] ?? [])), $allowed));
            $before = $t->waiters()->pluck('users.id')->sort()->values()->all();
            $t->waiters()->sync(collect($ids)->mapWithKeys(fn ($id) => [$id => ['assigned_by' => $by->id]])->all());
            if ($before !== collect($ids)->sort()->values()->all()) {
                $n++;
            }
        }

        return $n;
    }

    /** A call from the table (or a new QR order / online payment): a task for each waiter of the table. */
    public static function taskForCall(TableCall $c): int
    {
        $t = $c->table;
        if (! $t) {
            return 0;
        }
        $what = match ($c->type) {
            'ORDER' => 'serve the new QR order',
            'PAID' => 'customer paid online — tell the cashier, bring the receipt',
            'BILL' => 'bring the bill',
            'WATER' => 'bring water',
            'WAITER' => 'customer is calling you',
            default => 'customer needs something',
        };
        $mins = $c->type === 'ORDER' ? 25 : 5;
        $n = 0;
        foreach (static::waiters($t) as $w) {
            Task::create([
                'title' => 'Table '.$t->name.': '.$what, 'title_bn' => 'টেবিল '.$t->name.': '.$what,
                'description' => trim(($c->note ? $c->note."\n" : '').$t->location?->name.' · '.$c->created_at->format('h:i A')),
                'assignee_id' => $w->id, 'location_id' => $t->location_id, 'priority' => in_array($c->type, ['WAITER', 'BILL', 'PAID']) ? 'URGENT' : 'NORMAL',
                'due_at' => now()->addMinutes($mins), 'linked_type' => 'TableCall', 'linked_id' => $c->id, 'notify_on_done' => false,
            ]);
            Notifier::send($w, $c->label().' · Table '.$t->name, $c->note ?: 'Open My tasks', 'warning', '/tasks');
            $n++;
        }

        return $n;
    }

    /** Waiter pressed Start on the task = "I'm coming". */
    public static function started(Task $task, User $u): void
    {
        $c = TableCall::find($task->linked_id);
        if ($c && $c->status === 'OPEN') {
            $c->update(['status' => 'COMING', 'answered_by' => $u->id, 'answered_at' => now()]);
        }
    }

    /** Waiter finished the task: the call is done, the other waiters' tasks for it close too. */
    public static function finished(Task $task, User $u): void
    {
        $c = TableCall::find($task->linked_id);
        if ($c && $c->status !== 'DONE') {
            $c->update(['status' => 'DONE', 'answered_by' => $c->answered_by ?? $u->id, 'answered_at' => $c->answered_at ?? now(), 'done_at' => now()]);
        }
        static::closeTasks($task->linked_id, $u, $task->id);
    }

    /** The call was handled (from a staff screen or by another waiter): close the open tasks for it. */
    public static function closeTasks(int $callId, ?User $by = null, ?int $except = null): void
    {
        Task::where('linked_type', 'TableCall')->where('linked_id', $callId)->when($except, fn ($q) => $q->where('id', '!=', $except))
            ->whereIn('status', ['ASSIGNED', 'STARTED'])
            ->update(['status' => 'DONE', 'completed_at' => now(), 'submitted_at' => now(), 'proof_note' => 'Handled'.($by ? ' by '.$by->name : '')]);
    }

    /** Kitchen finished a dine-in order: tell the table's waiters to serve it. */
    public static function foodReady(PosOrder $o): void
    {
        if (! $o->dining_table_id || $o->lines()->where('kot_status', 'NEW')->exists()) {
            return;
        }
        $t = $o->table;
        foreach ($t ? static::waiters($t) : [] as $w) {
            Notifier::send($w, '✅ Food ready · Table '.$t->name, $o->number.' — collect it from the kitchen and serve', 'success', '/tasks');
        }
    }
}
