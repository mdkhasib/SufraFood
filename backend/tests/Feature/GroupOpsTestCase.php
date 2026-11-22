<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class GroupOpsTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Features::flush();
        \App\Services\LedgerService::flush();
    }

    protected function u(string $code): User
    {
        return User::where('employee_code', $code)->firstOrFail();
    }

    /** Act as a user on the API (fresh model so permission caches reset). */
    protected function api(string $code): User
    {
        $u = $this->u($code);
        Sanctum::actingAs($u);
        auth()->setUser($u);

        return $u;
    }

    protected function setting(string $k, $v): void
    {
        Features::setSetting($k, $v);
        Features::flush();
    }

    protected function feature(string $key, string $status, array $locationCodes = []): void
    {
        $f = \App\Models\Feature::where('key', $key)->firstOrFail();
        $f->update(['status' => $status]);
        $f->locations()->sync(\App\Models\Location::whereIn('code', $locationCodes)->pluck('id'));
        Features::flush();
    }

    protected function gps(string $locCode, float $offsetM = 0): array
    {
        $l = \App\Models\Location::where('code', $locCode)->first();

        return ['lat' => (float) $l->lat + $offsetM / 111320, 'lng' => (float) $l->lng, 'accuracy' => 10];
    }
}
