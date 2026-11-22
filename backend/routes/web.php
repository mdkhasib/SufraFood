<?php

use App\Http\Controllers\Web\AccountingController;
use App\Http\Controllers\Web\AdminController;
use App\Http\Controllers\Web\AttendanceController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\CateringController;
use App\Http\Controllers\Web\CrudController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\HrController;
use App\Http\Controllers\Web\InventoryController;
use App\Http\Controllers\Web\PosController;
use App\Http\Controllers\Web\PurchaseController;
use App\Http\Controllers\Web\RequisitionController;
use App\Http\Controllers\Web\SetupController;
use App\Http\Controllers\Web\TaskController;
use App\Http\Controllers\Web\TeamController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'form'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
Route::get('/lang/{lang}', [AuthController::class, 'lang'])->name('lang');
Route::get('/download/app', fn () => file_exists(public_path('downloads/sufra-groupops.apk')) ? response()->download(public_path('downloads/sufra-groupops.apk')) : abort(404, 'APK not built yet'))->name('apk');
Route::get('/app', fn () => view('app-guide', ['download' => request()->boolean('download')]))->name('app.guide');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/', [DashboardController::class, 'index'])->name('home');
    Route::get('/notifications', [DashboardController::class, 'notifications'])->name('notifications');
    Route::post('/notifications/read', [DashboardController::class, 'readAll'])->name('notifications.read');
    Route::get('/approvals', [DashboardController::class, 'approvals'])->name('approvals');
    Route::post('/approvals/{step}', [DashboardController::class, 'act'])->name('approvals.act');
    Route::get('/profile', [DashboardController::class, 'profile'])->name('profile');
    Route::post('/profile/password', [DashboardController::class, 'password'])->name('profile.password');

    // Super Admin
    Route::middleware('super')->prefix('super')->name('super.')->group(function () {
        Route::get('/features', [AdminController::class, 'features'])->name('features');
        Route::post('/features/{feature}', [AdminController::class, 'updateFeature'])->name('features.update');
        Route::post('/features-bulk', [AdminController::class, 'bulk'])->name('features.bulk');
        Route::post('/modules/{module}', [AdminController::class, 'updateModule'])->name('modules.update');
        Route::get('/admins', [AdminController::class, 'admins'])->name('admins');
        Route::post('/admins/{user}', [AdminController::class, 'toggleAdmin'])->name('admins.toggle');
        Route::get('/audit', [AdminController::class, 'audit'])->name('audit');
    });

    // Company settings: Super Admin, or Company Admins when the Super Admin allows it (checked in the controller)
    Route::get('/setup/settings', [SetupController::class, 'settings'])->name('setup.settings');
    Route::post('/setup/settings', [SetupController::class, 'saveSettings'])->name('setup.settings.save');
    Route::get('/setup/settings/bill-preview', [SetupController::class, 'billPreview'])->name('setup.bill.preview');

    // Company Admin setup
    Route::middleware('admin')->prefix('setup')->name('setup.')->group(function () {
        Route::get('/roles', [SetupController::class, 'roles'])->name('roles');
        Route::post('/roles', [SetupController::class, 'saveRoles'])->name('roles.save');
        Route::post('/roles/new', [SetupController::class, 'newRole'])->name('roles.new');
        Route::post('/roles/{role}/edit', [SetupController::class, 'updateRole'])->name('roles.update');
        Route::delete('/roles/{role}', [SetupController::class, 'deleteRole'])->name('roles.delete');
        Route::get('/approval-rules', [SetupController::class, 'rules'])->name('rules');
        Route::post('/approval-rules', [SetupController::class, 'saveRule'])->name('rules.save');
        Route::delete('/approval-rules/{rule}', [SetupController::class, 'deleteRule'])->name('rules.delete');
        Route::get('/delegations', [SetupController::class, 'delegations'])->name('delegations');
        Route::post('/delegations', [SetupController::class, 'saveDelegation'])->name('delegations.save');
        Route::delete('/delegations/{delegation}', [SetupController::class, 'deleteDelegation'])->name('delegations.delete');
        Route::post('/locations/{location}/geofence', [SetupController::class, 'geofence'])->name('geofence');
    });

    // generic master-data screens (permission checked per resource)
    Route::get('/m/{res}', [CrudController::class, 'index'])->name('crud.index');
    Route::get('/m/{res}/create', [CrudController::class, 'create'])->name('crud.create');
    Route::post('/m/{res}', [CrudController::class, 'store'])->name('crud.store');
    Route::get('/m/{res}/{id}/edit', [CrudController::class, 'edit'])->name('crud.edit');
    Route::put('/m/{res}/{id}', [CrudController::class, 'update'])->name('crud.update');
    Route::delete('/m/{res}/{id}', [CrudController::class, 'destroy'])->name('crud.destroy');
    Route::post('/m/users/{id}/reset-device', [CrudController::class, 'resetDevice'])->name('crud.reset-device');

    // Tasks
    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks')->middleware('perm:tasks.view_own|tasks.view_team');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store')->middleware('perm:tasks.assign');
    Route::post('/tasks/{task}/{action}', [TaskController::class, 'action'])->name('tasks.action')->middleware('perm:tasks.view_own|tasks.assign');
    Route::get('/task-templates', [TaskController::class, 'templates'])->name('tasks.templates')->middleware('perm:tasks.templates');
    Route::post('/task-templates', [TaskController::class, 'saveTemplate'])->name('tasks.templates.save')->middleware('perm:tasks.templates');
    Route::post('/task-templates/{template}/toggle', [TaskController::class, 'toggleTemplate'])->name('tasks.templates.toggle')->middleware('perm:tasks.templates');

    // Teams & org chart
    Route::get('/org', [TeamController::class, 'org'])->name('org')->middleware('perm:teams.view');
    Route::post('/org/{user}/manager', [TeamController::class, 'setManager'])->name('org.manager')->middleware('perm:teams.manage');
    Route::get('/teams', [TeamController::class, 'teams'])->name('teams')->middleware('perm:teams.view');
    Route::post('/teams', [TeamController::class, 'saveTeam'])->name('teams.save')->middleware('perm:teams.manage');
    Route::delete('/teams/{team}', [TeamController::class, 'deleteTeam'])->name('teams.delete')->middleware('perm:teams.manage');

    // Attendance & HR
    Route::get('/attendance/team', [AttendanceController::class, 'team'])->name('attendance.team')->middleware('perm:attendance.team_view');
    Route::post('/attendance/{attendance}/decide/{what}', [AttendanceController::class, 'decide'])->name('attendance.decide')->middleware('perm:attendance.team_view');
    Route::get('/attendance/report', [AttendanceController::class, 'report'])->name('attendance.report')->middleware('perm:attendance.team_view|attendance.manage');
    Route::get('/attendance/me', [AttendanceController::class, 'me'])->name('attendance.me');
    Route::get('/roster', [AttendanceController::class, 'roster'])->name('roster')->middleware('perm:shifts.manage');
    Route::post('/roster', [AttendanceController::class, 'saveRoster'])->name('roster.save')->middleware('perm:shifts.manage');
    Route::post('/roster/copy', [AttendanceController::class, 'copyWeek'])->name('roster.copy')->middleware('perm:shifts.manage');
    Route::get('/proxy-grants', [AttendanceController::class, 'grants'])->name('proxy')->middleware('perm:attendance.proxy_grant');
    Route::post('/proxy-grants', [AttendanceController::class, 'saveGrant'])->name('proxy.save')->middleware('perm:attendance.proxy_grant');
    Route::post('/proxy-grants/{grant}/revoke', [AttendanceController::class, 'revoke'])->name('proxy.revoke')->middleware('perm:attendance.proxy_grant');
    Route::get('/leave', [HrController::class, 'leave'])->name('leave')->middleware('perm:leave.request|leave.manage');
    Route::post('/leave', [HrController::class, 'requestLeave'])->name('leave.request')->middleware('perm:leave.request');
    Route::get('/payroll', [HrController::class, 'payroll'])->name('payroll')->middleware('perm:payroll.manage|acc.manage');
    Route::post('/payroll/generate', [HrController::class, 'generate'])->name('payroll.generate')->middleware('perm:payroll.manage');
    Route::get('/payroll/{run}', [HrController::class, 'run'])->name('payroll.run')->middleware('perm:payroll.manage|acc.manage');
    Route::post('/payroll/{run}/{action}', [HrController::class, 'runAction'])->name('payroll.action')->middleware('perm:payroll.manage|acc.manage');
    Route::get('/payslips', [HrController::class, 'payslips'])->name('payslips')->middleware('perm:payroll.view_own');
    Route::get('/payslip/{payslip}', [HrController::class, 'payslip'])->name('payslip');
    Route::get('/salary-advances', [HrController::class, 'advances'])->name('advances');
    Route::post('/salary-advances', [HrController::class, 'requestAdvance'])->name('advances.request')->middleware('perm:payroll.advance_request');
    Route::post('/salary-advances/{advance}/pay', [HrController::class, 'payAdvance'])->name('advances.pay')->middleware('perm:acc.manage');
    Route::get('/kpi', [HrController::class, 'kpi'])->name('kpi')->middleware('perm:kpi.view_own|kpi.view_team');

    // Requisitions
    Route::get('/requisitions', [RequisitionController::class, 'index'])->name('req.index');
    Route::get('/requisitions/new', [RequisitionController::class, 'create'])->name('req.create');
    Route::post('/requisitions', [RequisitionController::class, 'store'])->name('req.store');
    Route::get('/requisitions/{requisition}', [RequisitionController::class, 'show'])->name('req.show');
    Route::post('/requisitions/{requisition}/{action}', [RequisitionController::class, 'action'])->name('req.action');
    Route::get('/standing-orders', [RequisitionController::class, 'standing'])->name('req.standing')->middleware('perm:req.standing');
    Route::post('/standing-orders', [RequisitionController::class, 'saveStanding'])->name('req.standing.save')->middleware('perm:req.standing');

    // Inventory & production
    Route::get('/stock', [InventoryController::class, 'stock'])->name('inv.stock')->middleware('perm:inventory.view');
    Route::get('/stock/movements', [InventoryController::class, 'movements'])->name('inv.movements')->middleware('perm:inventory.view');
    Route::post('/stock/count', [InventoryController::class, 'count'])->name('inv.count')->middleware('perm:inventory.count');
    Route::post('/stock/wastage', [InventoryController::class, 'wastage'])->name('inv.wastage')->middleware('perm:inventory.wastage');
    Route::get('/recipes', [InventoryController::class, 'recipes'])->name('inv.recipes')->middleware('perm:inventory.view|production.manage');
    Route::post('/recipes/{item}', [InventoryController::class, 'saveRecipe'])->name('inv.recipes.save')->middleware('perm:production.manage|inventory.manage');
    Route::get('/production', [InventoryController::class, 'production'])->name('prod.index')->middleware('perm:production.manage|production.work');
    Route::post('/production/{action}', [InventoryController::class, 'productionAction'])->name('prod.action')->middleware('perm:production.manage|production.work');
    Route::get('/deliveries', [InventoryController::class, 'deliveries'])->name('deliveries')->middleware('perm:dispatch.drive|req.fulfil');

    // Purchase
    Route::get('/purchase', [PurchaseController::class, 'index'])->name('pur.index')->middleware('perm:purchase.manage|purchase.buy');
    Route::post('/purchase/{requisition}/assign', [PurchaseController::class, 'assign'])->name('pur.assign')->middleware('perm:purchase.manage');
    Route::get('/purchase/assignment/{assignment}', [PurchaseController::class, 'assignment'])->name('pur.assignment');
    Route::post('/purchase/assignment/{assignment}/{action}', [PurchaseController::class, 'assignmentAction'])->name('pur.assignment.action')->middleware('perm:purchase.buy');
    Route::get('/wallets', [PurchaseController::class, 'wallets'])->name('pur.wallets')->middleware('perm:purchase.advance|purchase.manage|purchase.buy');
    Route::post('/wallets/{user}/{action}', [PurchaseController::class, 'walletAction'])->name('pur.wallet.action')->middleware('perm:purchase.advance');
    Route::get('/claims', [PurchaseController::class, 'claims'])->name('pur.claims');
    Route::post('/claims', [PurchaseController::class, 'newClaim'])->name('pur.claims.new')->middleware('perm:purchase.buy');
    Route::post('/claims/{claim}/pay', [PurchaseController::class, 'payClaim'])->name('pur.claims.pay')->middleware('perm:acc.manage');
    Route::get('/vendor-bills', [PurchaseController::class, 'bills'])->name('pur.bills')->middleware('perm:acc.view|purchase.manage');
    Route::post('/vendor-bills/{bill}/pay', [PurchaseController::class, 'payBill'])->name('pur.bills.pay')->middleware('perm:acc.manage');

    // Catering
    Route::get('/catering', [CateringController::class, 'index'])->name('cat.index')->middleware('perm:catering.manage');
    Route::get('/catering/new', [CateringController::class, 'create'])->name('cat.create')->middleware('perm:catering.manage');
    Route::post('/catering', [CateringController::class, 'store'])->name('cat.store')->middleware('perm:catering.manage');
    Route::get('/catering/{event}', [CateringController::class, 'show'])->name('cat.show')->middleware('perm:catering.manage');
    Route::post('/catering/{event}/{action}', [CateringController::class, 'action'])->name('cat.action')->middleware('perm:catering.manage');

    // POS (web only)
    Route::get('/pos', [PosController::class, 'index'])->name('pos')->middleware('perm:pos.sell');
    Route::post('/pos/shift/open', [PosController::class, 'open'])->name('pos.open')->middleware('perm:pos.sell');
    Route::post('/pos/shift/close', [PosController::class, 'close'])->name('pos.close')->middleware('perm:pos.sell');
    Route::post('/pos/orders', [PosController::class, 'store'])->name('pos.store')->middleware('perm:pos.sell');
    Route::post('/pos/orders/{order}/pay', [PosController::class, 'pay'])->name('pos.pay')->middleware('perm:pos.sell');
    Route::post('/pos/orders/{order}/void', [PosController::class, 'void'])->name('pos.void')->middleware('perm:pos.void');
    Route::get('/pos/orders', [PosController::class, 'orders'])->name('pos.orders')->middleware('perm:pos.sell|pos.void');
    Route::get('/pos/receipt/{order}', [PosController::class, 'receipt'])->name('pos.receipt')->middleware('perm:pos.sell|pos.void');
    Route::get('/kds', [PosController::class, 'kds'])->name('pos.kds')->middleware('perm:pos.kds');
    Route::get('/kds/feed', [PosController::class, 'kdsFeed'])->name('pos.kds.feed')->middleware('perm:pos.kds');
    Route::post('/kds/{line}/ready', [PosController::class, 'ready'])->name('pos.kds.ready')->middleware('perm:pos.kds');
    Route::get('/pos/summary', [PosController::class, 'summary'])->name('pos.summary')->middleware('perm:pos.void|reports.management|acc.view');

    // Accounting (web only)
    Route::middleware('perm:acc.view')->prefix('accounting')->name('acc.')->group(function () {
        Route::get('/', [AccountingController::class, 'index'])->name('index');
        Route::get('/journal', [AccountingController::class, 'journal'])->name('journal');
        Route::get('/journal/{entry}', [AccountingController::class, 'entry'])->name('entry');
        Route::post('/journal', [AccountingController::class, 'post'])->name('post')->middleware('perm:acc.manage');
        Route::get('/expenses', [AccountingController::class, 'expenses'])->name('expenses');
        Route::post('/expenses', [AccountingController::class, 'saveExpense'])->name('expenses.save')->middleware('perm:acc.manage');
        Route::post('/transfer', [AccountingController::class, 'transfer'])->name('transfer')->middleware('perm:acc.manage');
        Route::get('/reports/{report}', [AccountingController::class, 'report'])->name('report');
        Route::post('/periods/close', [AccountingController::class, 'closePeriod'])->name('close')->middleware('perm:acc.manage');
    });
});
