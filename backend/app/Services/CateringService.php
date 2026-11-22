<?php

namespace App\Services;

use App\Exceptions\BizException;
use App\Models\CateringEvent;
use App\Models\CateringPayment;
use App\Models\Location;
use App\Models\MoneyAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CateringService
{
    public static function confirm(CateringEvent $e, User $u): CateringEvent
    {
        if (! $u->hasPerm('catering.manage')) {
            throw new BizException('Not allowed.');
        }
        if ($e->status !== 'INQUIRY') {
            throw new BizException('Event is already '.strtolower($e->status).'.');
        }
        $cat = Location::where('type', 'CATERING')->first();
        if (! $cat) {
            throw new BizException('No catering location set up.');
        }
        DB::transaction(function () use ($e, $u, $cat) {
            $food = $e->items()->where('kind', 'FOOD')->get();
            $equip = $e->items()->where('kind', 'EQUIPMENT')->get();
            $neededBy = $e->event_at->copy()->subHours(3);
            if ($food->count()) {
                RequisitionService::create($u, [
                    'type' => 'FOOD_ORDER', 'force_from_location_id' => $cat->id, 'needed_by' => $neededBy, 'catering_event_id' => $e->id,
                    'lines' => $food->map(fn ($i) => ['item_id' => $i->item_id, 'qty' => $i->qty])->all(), 'notes' => 'Catering '.$e->number.' · '.$e->title,
                ], true, 'CATERING');
            }
            if ($equip->count()) {
                RequisitionService::create($u, [
                    'type' => 'SUPPLIES', 'force_from_location_id' => $cat->id, 'needed_by' => $neededBy->copy()->subHours(2), 'catering_event_id' => $e->id,
                    'lines' => $equip->map(fn ($i) => ['item_id' => $i->item_id, 'qty' => $i->qty])->all(), 'notes' => 'Equipment for '.$e->number,
                ], true, 'CATERING');
            }
            foreach ($e->staff as $s) {
                TaskService::systemTask($s, 'Event duty: '.$e->title.' @ '.$e->venue.' ('.($s->pivot->duty ?? 'service').')', $e->event_at->copy()->subHours(2), $e, $u);
            }
            if ($e->manager_id) {
                TaskService::systemTask(User::find($e->manager_id), 'Equipment return check: '.$e->number, $e->event_at->copy()->addDay()->setTime(12, 0), $e, $u);
            }
            $e->update(['status' => 'CONFIRMED']);
            LedgerService::post('Catering invoice '.$e->number.' ('.$e->client_name.')', [
                ['code' => '1100', 'debit' => $e->total, 'location_id' => $cat->id],
                ['code' => '4100', 'credit' => $e->total, 'location_id' => $cat->id],
            ], $e, $cat->id);
        });

        return $e->fresh();
    }

    public static function recordPayment(CateringEvent $e, User $u, int $moneyAccountId, float $amount, ?string $ref = null): CateringPayment
    {
        if (! $u->hasPerm('catering.manage') && ! $u->hasPerm('acc.manage')) {
            throw new BizException('Not allowed.');
        }
        if ($amount <= 0 || $amount > ($e->total - $e->paid) + 0.01) {
            throw new BizException('Amount must be between 1 and the balance due ৳'.number_format($e->total - $e->paid).'.');
        }
        $ma = MoneyAccount::findOrFail($moneyAccountId);

        return DB::transaction(function () use ($e, $ma, $amount, $ref) {
            $p = CateringPayment::create(['catering_event_id' => $e->id, 'money_account_id' => $ma->id, 'amount' => $amount, 'reference' => $ref, 'date' => today()]);
            $cat = Location::where('type', 'CATERING')->value('id');
            if ($e->status === 'INQUIRY') {
                // advance before confirmation is still a receivable credit
            }
            LedgerService::post('Catering payment '.$e->number, [LedgerService::moneyLine($ma, $amount, 0), ['code' => '1100', 'credit' => $amount, 'location_id' => $cat]], $p, $cat);
            $e->update(['paid' => round($e->paid + $amount, 2)]);

            return $p;
        });
    }

    public static function setStatus(CateringEvent $e, User $u, string $status): CateringEvent
    {
        if (! $u->hasPerm('catering.manage')) {
            throw new BizException('Not allowed.');
        }
        $allowed = ['CONFIRMED' => ['DELIVERED', 'CANCELLED'], 'DELIVERED' => ['CLOSED'], 'INQUIRY' => ['CANCELLED']];
        if (! in_array($status, $allowed[$e->status] ?? [])) {
            throw new BizException('Cannot move from '.$e->status.' to '.$status.'.');
        }
        $e->update(['status' => $status]);

        return $e;
    }
}
