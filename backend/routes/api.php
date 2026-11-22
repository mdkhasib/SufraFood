<?php

use App\Http\Controllers\Api\AttendanceController as A;
use App\Http\Controllers\Api\AuthController as Auth;
use App\Http\Controllers\Api\OpsController as Ops;
use App\Http\Controllers\Api\PeopleController as People;
use App\Http\Controllers\Api\PurchaseController as Pur;
use App\Http\Controllers\Api\RequisitionController as Req;
use Illuminate\Support\Facades\Route;

Route::post('/login', [Auth::class, 'login'])->middleware('throttle:20,1');
Route::get('/ping', fn () => ['ok' => true, 'app' => config('app.name'), 'time' => now()->toIso8601String()]);
Route::get('/branding', fn () => ['name' => \App\Support\Brand::name(), 'name_bn' => \App\Support\Features::setting('company.name_bn'), 'tagline' => \App\Support\Features::setting('company.tagline'), 'logo' => \App\Support\Brand::logoUrl(), 'initials' => \App\Support\Brand::initials()]);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [Auth::class, 'logout']);
    Route::get('/me', [Auth::class, 'me']);
    Route::post('/me/language', [Auth::class, 'language']);
    Route::get('/home', [Auth::class, 'home']);
    Route::get('/notifications', [Auth::class, 'notifications']);
    Route::post('/notifications/read-all', [Auth::class, 'readAll']);
    Route::get('/dashboard', [Auth::class, 'dashboard'])->middleware('perm:reports.management');

    // attendance & shifts
    Route::post('/attendance/check-in', [A::class, 'checkIn'])->middleware('perm:attendance.self');
    Route::post('/attendance/check-out', [A::class, 'checkOut'])->middleware('perm:attendance.self');
    Route::get('/attendance/month', [A::class, 'month']);
    Route::get('/attendance/team', [A::class, 'team'])->middleware('perm:attendance.team_view');
    Route::get('/attendance/proxy-list', [A::class, 'proxyList']);
    Route::post('/attendance/proxy-check-in', [A::class, 'proxyCheckIn']);
    Route::post('/attendance/proxy-check-out', [A::class, 'proxyCheckOut']);
    Route::get('/attendance/proxy-grants', [A::class, 'grants'])->middleware('perm:attendance.proxy_grant');
    Route::post('/attendance/proxy-grants', [A::class, 'createGrant'])->middleware('perm:attendance.proxy_grant');
    Route::delete('/attendance/proxy-grants/{grant}', [A::class, 'revokeGrant'])->middleware('perm:attendance.proxy_grant');
    Route::post('/attendance/corrections', [A::class, 'correction'])->middleware('perm:attendance.correction_request');
    Route::post('/attendance/{attendance}/remote-decision', [A::class, 'remoteDecision'])->middleware('perm:attendance.team_view');
    Route::post('/attendance/{attendance}/ot-decision', [A::class, 'otDecision'])->middleware('perm:attendance.team_view');
    Route::get('/roster', [A::class, 'roster'])->middleware('perm:shifts.view_own');

    // tasks, team, leave, kpi, payroll
    Route::get('/tasks', [People::class, 'tasks'])->middleware('perm:tasks.view_own');
    Route::post('/tasks', [People::class, 'createTask'])->middleware('perm:tasks.assign');
    Route::post('/tasks/{task}/start', [People::class, 'startTask'])->middleware('perm:tasks.view_own');
    Route::post('/tasks/{task}/complete', [People::class, 'completeTask'])->middleware('perm:tasks.view_own');
    Route::post('/tasks/{task}/review', [People::class, 'reviewTask'])->middleware('perm:tasks.assign');
    Route::post('/tasks/{task}/remind', [People::class, 'remindTask'])->middleware('perm:tasks.assign|tasks.view_team');
    Route::get('/team', [People::class, 'team']);
    Route::get('/assign-options', [People::class, 'assignOptions'])->middleware('perm:tasks.assign');
    Route::get('/org', [People::class, 'org'])->middleware('perm:teams.view');
    Route::get('/teams', [People::class, 'teams'])->middleware('perm:teams.view');
    Route::get('/leave', [People::class, 'leave'])->middleware('perm:leave.request');
    Route::post('/leave', [People::class, 'requestLeave'])->middleware('perm:leave.request');
    Route::get('/kpi', [People::class, 'kpi'])->middleware('perm:kpi.view_own');
    Route::get('/kpi/team', [People::class, 'teamKpi'])->middleware('perm:kpi.view_team');
    Route::get('/payslips', [People::class, 'payslips'])->middleware('perm:payroll.view_own');
    Route::get('/salary-advances', [People::class, 'advances'])->middleware('perm:payroll.advance_request');
    Route::post('/salary-advances', [People::class, 'requestAdvance'])->middleware('perm:payroll.advance_request');

    // requisitions & approvals
    Route::get('/requisitions', [Req::class, 'index']);
    Route::get('/requisition-types', [Req::class, 'types']);
    Route::get('/items', [Req::class, 'items']);
    Route::post('/requisitions', [Req::class, 'store']);
    Route::get('/requisitions/{requisition}', [Req::class, 'show']);
    Route::post('/requisitions/{requisition}/{action}', [Req::class, 'action'])->where('action', '[a-z\-]+');
    Route::get('/drivers', [Req::class, 'drivers']);
    Route::get('/stock', [Req::class, 'stock'])->middleware('perm:inventory.view');
    Route::get('/approvals', [Req::class, 'approvals']);
    Route::post('/approvals/{step}', [Req::class, 'act']);

    // purchase
    Route::get('/purchases', [Pur::class, 'mine'])->middleware('perm:purchase.buy');
    Route::get('/purchases/{assignment}', [Pur::class, 'show']);
    Route::post('/purchases/{assignment}/accept', [Pur::class, 'accept'])->middleware('perm:purchase.buy');
    Route::post('/purchases/{assignment}/receipt', [Pur::class, 'receipt'])->middleware('perm:purchase.buy');
    Route::post('/purchases/{assignment}/delivered', [Pur::class, 'delivered'])->middleware('perm:purchase.buy');
    Route::get('/wallet', [Pur::class, 'wallet'])->middleware('perm:purchase.buy');
    Route::post('/claims', [Pur::class, 'claim'])->middleware('perm:purchase.buy');
    Route::get('/purchase/requests', [Pur::class, 'requests'])->middleware('perm:purchase.manage');
    Route::get('/purchase/options', [Pur::class, 'options'])->middleware('perm:purchase.manage');
    Route::post('/purchase/requests/{requisition}/assign', [Pur::class, 'assign'])->middleware('perm:purchase.manage');

    // deliveries & production
    Route::get('/deliveries', [Ops::class, 'deliveries'])->middleware('perm:dispatch.drive');
    Route::post('/deliveries/{dispatch}/pickup', [Ops::class, 'pickup'])->middleware('perm:dispatch.drive');
    Route::post('/deliveries/{dispatch}/deliver', [Ops::class, 'deliver'])->middleware('perm:dispatch.drive');
    Route::get('/production', [Ops::class, 'production'])->middleware('perm:production.work|production.manage');
    Route::post('/production/{order}/{action}', [Ops::class, 'productionAction'])->middleware('perm:production.work|production.manage');
});
