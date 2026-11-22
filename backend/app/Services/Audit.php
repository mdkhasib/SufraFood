<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class Audit
{
    public static function log(string $action, ?Model $entity = null, ?array $before = null, ?array $after = null): void
    {
        $req = request();
        AuditLog::create([
            'user_id' => auth()->id() ?? optional($req?->user())->id,
            'action' => $action,
            'entity_type' => $entity ? class_basename($entity) : null,
            'entity_id' => $entity?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $req?->ip(),
            'device' => $req?->header('X-Device-Id') ?? substr((string) $req?->userAgent(), 0, 120),
        ]);
    }
}
