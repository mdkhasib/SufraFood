<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---------- Platform control ----------
        Schema::create('features', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->string('module');
            $t->string('name');
            $t->json('depends_on')->nullable();
            $t->string('status')->default('OFF'); // OFF, PILOT, ON
            $t->timestamps();
        });
        Schema::create('feature_locations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
        });
        Schema::create('settings', function (Blueprint $t) {
            $t->id();
            $t->string('key')->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable();
            $t->string('action');
            $t->string('entity_type')->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip')->nullable();
            $t->string('device')->nullable();
            $t->timestamps();
            $t->index(['entity_type', 'entity_id']);
        });
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type')->default('info');
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('link')->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'read_at']);
        });
        Schema::create('delegations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            $t->date('from_date');
            $t->date('to_date');
            $t->timestamps();
        });

        // ---------- Money ----------
        Schema::create('payment_methods', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type'); // CASH, BANK, MFS, CARD
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('type'); // ASSET, LIABILITY, EQUITY, INCOME, EXPENSE
            $t->boolean('system')->default(false);
            $t->timestamps();
        });
        Schema::create('money_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type'); // CASH, BANK, MFS, PETTY, DRAWER
            $t->foreignId('account_id')->constrained();
            $t->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('account_no')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('fiscal_periods', function (Blueprint $t) {
            $t->id();
            $t->string('month')->unique(); // 2026-11
            $t->boolean('closed')->default(false);
            $t->foreignId('closed_by')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->date('date');
            $t->string('memo');
            $t->string('source_type')->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('posted_by')->nullable();
            $t->timestamps();
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('money_account_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable(); // sub-ledger (employee advances / payables)
            $t->foreignId('vendor_id')->nullable();
            $t->decimal('debit', 14, 2)->default(0);
            $t->decimal('credit', 14, 2)->default(0);
            $t->string('memo')->nullable();
            $t->timestamps();
        });
        Schema::create('expenses', function (Blueprint $t) {
            $t->id();
            $t->date('date');
            $t->foreignId('account_id')->constrained();
            $t->foreignId('money_account_id')->constrained();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->foreignId('journal_entry_id')->nullable();
            $t->timestamps();
        });

        // ---------- Approvals ----------
        Schema::create('approval_rules', function (Blueprint $t) {
            $t->id();
            $t->string('request_type');
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('min_amount', 14, 2)->default(0);
            $t->decimal('max_amount', 14, 2)->nullable();
            $t->json('levels'); // [{by: role|user|direct_manager|location_manager, value}]
            $t->unsignedInteger('sla_minutes')->nullable();
            $t->timestamps();
        });
        Schema::create('approval_steps', function (Blueprint $t) {
            $t->id();
            $t->string('approvable_type');
            $t->unsignedBigInteger('approvable_id');
            $t->unsignedInteger('level');
            $t->string('approver_by');
            $t->string('approver_value')->nullable();
            $t->foreignId('approver_user_id')->nullable();
            $t->string('status')->default('WAITING'); // WAITING, PENDING, APPROVED, REJECTED, SKIPPED
            $t->foreignId('acted_by')->nullable();
            $t->foreignId('on_behalf_of')->nullable();
            $t->text('comment')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('acted_at')->nullable();
            $t->timestamps();
            $t->index(['approvable_type', 'approvable_id']);
        });

        // ---------- Tasks ----------
        Schema::create('task_templates', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('title_bn')->nullable();
            $t->text('description')->nullable();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('team_lead_id')->nullable()->constrained('users')->nullOnDelete(); // all on-shift members of this lead's team
            $t->foreignId('created_by')->constrained('users');
            $t->string('recurrence')->default('DAILY'); // DAILY, WEEKLY
            $t->string('weekdays')->nullable(); // 0..6 csv for weekly
            $t->time('due_time');
            $t->boolean('proof_required')->default(false);
            $t->boolean('active')->default(true);
            $t->date('last_generated_for')->nullable();
            $t->timestamps();
        });
        Schema::create('tasks', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->string('title_bn')->nullable();
            $t->text('description')->nullable();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('assignee_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('template_id')->nullable()->constrained('task_templates')->nullOnDelete();
            $t->string('priority')->default('NORMAL');
            $t->dateTime('due_at');
            $t->boolean('proof_required')->default(false);
            $t->string('status')->default('ASSIGNED'); // ASSIGNED, STARTED, SUBMITTED, DONE, CANCELLED
            $t->dateTime('started_at')->nullable();
            $t->dateTime('submitted_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->unsignedInteger('rework_count')->default(0);
            $t->text('proof_note')->nullable();
            $t->string('proof_photo')->nullable();
            $t->boolean('escalated')->default(false);
            $t->string('linked_type')->nullable();
            $t->unsignedBigInteger('linked_id')->nullable();
            $t->timestamps();
            $t->index(['assignee_id', 'due_at']);
        });

        // ---------- Shifts & attendance ----------
        Schema::create('shift_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->string('code');
            $t->string('name');
            $t->time('start_time');
            $t->time('end_time');
            $t->time('start_time2')->nullable();
            $t->time('end_time2')->nullable();
            $t->unsignedInteger('break_minutes')->default(0);
            $t->timestamps();
        });
        Schema::create('rosters', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_off')->default(false);
            $t->boolean('no_show_notified')->default(false);
            $t->timestamps();
            $t->unique(['user_id', 'date']);
        });
        Schema::create('attendances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->dateTime('check_in_at')->nullable();
            $t->dateTime('check_out_at')->nullable();
            $t->decimal('in_lat', 10, 7)->nullable();
            $t->decimal('in_lng', 10, 7)->nullable();
            $t->decimal('in_accuracy', 8, 2)->nullable();
            $t->unsignedInteger('in_distance_m')->nullable();
            $t->decimal('out_lat', 10, 7)->nullable();
            $t->decimal('out_lng', 10, 7)->nullable();
            $t->string('method')->default('SELF'); // SELF, PROXY, REMOTE, CORRECTION
            $t->foreignId('by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('out_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('status')->default('PRESENT'); // PRESENT, LATE
            $t->unsignedInteger('late_minutes')->default(0);
            $t->unsignedInteger('worked_minutes')->default(0);
            $t->unsignedInteger('ot_minutes')->default(0);
            $t->string('ot_status')->default('NONE'); // NONE, PENDING, APPROVED, REJECTED
            $t->string('remote_status')->nullable(); // PENDING, APPROVED, REJECTED
            $t->boolean('auto_closed')->default(false);
            $t->string('device_id')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'date']);
        });
        Schema::create('proxy_grants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('manager_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('proxy_user_id')->constrained('users')->cascadeOnDelete();
            $t->date('valid_from')->nullable();
            $t->date('valid_to')->nullable();
            $t->string('reason')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('proxy_grant_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('proxy_grant_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
        });
        Schema::create('attendance_corrections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('date');
            $t->time('check_in')->nullable();
            $t->time('check_out')->nullable();
            $t->string('reason');
            $t->string('status')->default('PENDING');
            $t->timestamps();
        });
        Schema::create('shift_swaps', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('target_id')->constrained('users')->cascadeOnDelete();
            $t->date('date');
            $t->string('status')->default('PENDING_TARGET'); // PENDING_TARGET, PENDING_MANAGER, APPROVED, REJECTED
            $t->string('reason')->nullable();
            $t->timestamps();
        });
        Schema::create('leave_types', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('name_bn')->nullable();
            $t->unsignedInteger('days_per_year')->default(0);
            $t->boolean('paid')->default(true);
            $t->timestamps();
        });
        Schema::create('leave_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('leave_type_id')->constrained();
            $t->date('from_date');
            $t->date('to_date');
            $t->decimal('days', 5, 1);
            $t->string('reason')->nullable();
            $t->string('status')->default('PENDING');
            $t->timestamps();
        });
        Schema::create('holidays', function (Blueprint $t) {
            $t->id();
            $t->date('date')->unique();
            $t->string('name');
            $t->timestamps();
        });

        // ---------- Inventory ----------
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->string('code')->unique();
            $t->string('name');
            $t->string('name_bn')->nullable();
            $t->string('type'); // RAW, SEMI, MENU, CONSUMABLE, ASSET
            $t->string('category')->nullable();
            $t->string('unit');
            $t->string('buy_unit')->nullable();
            $t->decimal('buy_factor', 12, 3)->default(1);
            $t->decimal('last_price', 12, 2)->default(0);
            $t->decimal('sale_price', 12, 2)->default(0);
            $t->decimal('reorder_level', 12, 3)->default(0);
            $t->string('production_at')->default('NONE'); // NONE, CENTRAL, BRANCH
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('stocks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 14, 3)->default(0);
            $t->timestamps();
            $t->unique(['location_id', 'item_id']);
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 14, 3); // signed
            $t->string('type'); // OPENING, PURCHASE_IN, TRANSFER_OUT, TRANSFER_IN, PRODUCTION_USE, PRODUCTION_OUT, SALE, WASTAGE, ADJUSTMENT
            $t->decimal('unit_cost', 12, 2)->default(0);
            $t->string('ref_type')->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('reason')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('stock_counts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->decimal('system_qty', 14, 3);
            $t->decimal('counted_qty', 14, 3);
            $t->decimal('variance', 14, 3);
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('recipes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('item_id')->unique()->constrained()->cascadeOnDelete();
            $t->decimal('yield_qty', 12, 3)->default(1);
            $t->timestamps();
        });
        Schema::create('recipe_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained();
            $t->decimal('qty', 12, 4);
        });

        // ---------- Requisitions ----------
        Schema::create('requisitions', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->string('type'); // FOOD_ORDER, RAW_MATERIAL, SUPPLIES, EQUIPMENT, REPAIR, PURCHASE, TRANSFER
            $t->foreignId('from_location_id')->constrained('locations');
            $t->foreignId('to_location_id')->constrained('locations');
            $t->foreignId('requester_id')->constrained('users');
            $t->dateTime('needed_by')->nullable();
            $t->string('priority')->default('NORMAL'); // NORMAL, URGENT
            $t->string('status')->default('DRAFT');
            $t->string('source')->default('MANUAL'); // MANUAL, STANDING, AUTO, CATERING
            $t->decimal('estimated_amount', 14, 2)->default(0);
            $t->text('notes')->nullable();
            $t->text('reject_reason')->nullable();
            $t->foreignId('accepted_by')->nullable();
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('dispatched_at')->nullable();
            $t->dateTime('received_at')->nullable();
            $t->foreignId('received_by')->nullable();
            $t->foreignId('catering_event_id')->nullable();
            $t->timestamps();
        });
        Schema::create('requisition_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->nullable()->constrained();
            $t->string('description')->nullable();
            $t->decimal('qty_requested', 14, 3);
            $t->decimal('qty_approved', 14, 3)->nullable();
            $t->decimal('qty_sent', 14, 3)->nullable();
            $t->decimal('qty_received', 14, 3)->nullable();
            $t->decimal('est_price', 12, 2)->default(0);
            $t->string('note')->nullable();
        });
        Schema::create('requisition_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->constrained('requisitions')->cascadeOnDelete();
            $t->foreignId('child_id')->constrained('requisitions')->cascadeOnDelete();
        });
        Schema::create('requisition_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $t->string('from_status')->nullable();
            $t->string('to_status');
            $t->foreignId('user_id')->nullable();
            $t->string('note')->nullable();
            $t->timestamps();
        });
        Schema::create('standing_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained()->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday'); // 0=Sun .. 6=Sat (Carbon dayOfWeek)
            $t->decimal('qty', 12, 3);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->unique(['location_id', 'item_id', 'weekday']);
        });

        // ---------- Production & dispatch ----------
        Schema::create('production_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('location_id')->constrained();
            $t->foreignId('item_id')->constrained();
            $t->decimal('qty', 12, 3);
            $t->date('planned_for');
            $t->string('status')->default('PLANNED'); // PLANNED, IN_PROGRESS, DONE
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('dispatches', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_location_id')->constrained('locations');
            $t->foreignId('to_location_id')->constrained('locations');
            $t->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('vehicle')->nullable();
            $t->string('status')->default('READY'); // READY, PICKED_UP, DELIVERED, RECEIVED
            $t->dateTime('picked_up_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->timestamps();
        });

        // ---------- Purchase ----------
        Schema::create('vendors', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('phone')->nullable();
            $t->string('address')->nullable();
            $t->string('items_supplied')->nullable();
            $t->string('terms')->default('CASH'); // CASH, CREDIT
            $t->unsignedInteger('credit_days')->default(0);
            $t->timestamps();
        });
        Schema::create('purchase_assignments', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $t->foreignId('buyer_id')->constrained('users');
            $t->foreignId('assigned_by')->constrained('users');
            $t->dateTime('deadline_at');
            $t->string('funding'); // CASH_ADVANCE, PETTY_CASH, OWN_MONEY, VENDOR_CREDIT
            $t->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('petty_account_id')->nullable()->constrained('money_accounts')->nullOnDelete();
            $t->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $t->string('status')->default('ASSIGNED'); // ASSIGNED, ACCEPTED, BOUGHT, DELIVERED, SETTLED
            $t->decimal('actual_amount', 14, 2)->nullable();
            $t->string('receipt_photo')->nullable();
            $t->text('receipt_note')->nullable();
            $t->boolean('price_flag')->default(false);
            $t->dateTime('accepted_at')->nullable();
            $t->dateTime('bought_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->timestamps();
        });
        Schema::create('purchase_assignment_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_assignment_id')->constrained()->cascadeOnDelete();
            $t->foreignId('requisition_line_id')->constrained()->cascadeOnDelete();
            $t->decimal('qty', 14, 3);
            $t->decimal('unit_price', 12, 2);
        });
        Schema::create('wallet_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('type'); // ISSUE, SPEND, RETURN
            $t->decimal('amount', 14, 2);
            $t->foreignId('money_account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('ref_type')->nullable();
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('reimbursement_claims', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('purchase_assignment_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 14, 2);
            $t->string('description');
            $t->string('receipt_photo')->nullable();
            $t->string('status')->default('PENDING'); // PENDING, APPROVED, REJECTED, PAID
            $t->foreignId('paid_from')->nullable()->constrained('money_accounts')->nullOnDelete();
            $t->dateTime('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vendor_bills', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('vendor_id')->constrained();
            $t->foreignId('purchase_assignment_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 14, 2);
            $t->decimal('paid_amount', 14, 2)->default(0);
            $t->date('bill_date');
            $t->date('due_date');
            $t->string('status')->default('OPEN'); // OPEN, PARTIAL, PAID
            $t->timestamps();
        });
        Schema::create('vendor_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('vendor_bill_id')->constrained()->cascadeOnDelete();
            $t->foreignId('money_account_id')->constrained();
            $t->decimal('amount', 14, 2);
            $t->string('reference')->nullable();
            $t->date('date');
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });

        // ---------- Catering ----------
        Schema::create('catering_events', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->string('title');
            $t->string('client_name');
            $t->string('client_phone')->nullable();
            $t->dateTime('event_at');
            $t->string('venue');
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->unsignedInteger('radius_m')->default(100);
            $t->unsignedInteger('guests');
            $t->string('package_name')->nullable();
            $t->decimal('price_per_head', 12, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->decimal('paid', 14, 2)->default(0);
            $t->string('status')->default('INQUIRY'); // INQUIRY, CONFIRMED, DELIVERED, CLOSED, CANCELLED
            $t->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('catering_event_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catering_event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained();
            $t->decimal('qty', 12, 3);
            $t->string('kind')->default('FOOD'); // FOOD, EQUIPMENT
        });
        Schema::create('catering_event_staff', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catering_event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('duty')->nullable();
        });
        Schema::create('catering_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('catering_event_id')->constrained()->cascadeOnDelete();
            $t->foreignId('money_account_id')->constrained();
            $t->decimal('amount', 14, 2);
            $t->string('reference')->nullable();
            $t->date('date');
            $t->timestamps();
        });

        // ---------- POS ----------
        Schema::create('dining_tables', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->unsignedInteger('seats')->default(4);
            $t->timestamps();
        });
        Schema::create('cashier_shifts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained();
            $t->foreignId('cashier_id')->constrained('users');
            $t->dateTime('opened_at');
            $t->decimal('opening_cash', 12, 2)->default(0);
            $t->dateTime('closed_at')->nullable();
            $t->decimal('expected_cash', 12, 2)->nullable();
            $t->decimal('counted_cash', 12, 2)->nullable();
            $t->decimal('difference', 12, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('pos_orders', function (Blueprint $t) {
            $t->id();
            $t->string('number')->unique();
            $t->foreignId('location_id')->constrained();
            $t->foreignId('cashier_id')->constrained('users');
            $t->foreignId('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $t->string('order_type')->default('DINE_IN'); // DINE_IN, TAKEAWAY, DELIVERY
            $t->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $t->string('customer_phone')->nullable();
            $t->string('status')->default('OPEN'); // OPEN, PAID, VOID
            $t->decimal('subtotal', 12, 2)->default(0);
            $t->decimal('discount', 12, 2)->default(0);
            $t->decimal('service_charge', 12, 2)->default(0);
            $t->decimal('vat', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->string('void_reason')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('pos_order_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('item_id')->constrained();
            $t->decimal('qty', 10, 2);
            $t->decimal('price', 12, 2);
            $t->decimal('total', 12, 2);
            $t->string('kot_status')->default('NEW'); // NEW, READY, SERVED
            $t->string('note')->nullable();
        });
        Schema::create('pos_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('pos_order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('payment_method_id')->constrained();
            $t->decimal('amount', 12, 2);
            $t->string('reference')->nullable();
        });

        // ---------- Payroll & KPI ----------
        Schema::create('payroll_runs', function (Blueprint $t) {
            $t->id();
            $t->string('month'); // 2026-10
            $t->string('status')->default('DRAFT'); // DRAFT, PENDING_APPROVAL, APPROVED, PAID
            $t->decimal('total_net', 14, 2)->default(0);
            $t->foreignId('created_by')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('payslips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('location_id')->nullable();
            $t->decimal('basic', 12, 2);
            $t->decimal('house_rent', 12, 2);
            $t->decimal('medical', 12, 2);
            $t->decimal('conveyance', 12, 2);
            $t->unsignedInteger('rostered_days')->default(0);
            $t->unsignedInteger('present_days')->default(0);
            $t->decimal('absent_days', 5, 1)->default(0);
            $t->decimal('leave_days', 5, 1)->default(0);
            $t->unsignedInteger('late_count')->default(0);
            $t->decimal('ot_hours', 8, 2)->default(0);
            $t->decimal('ot_amount', 12, 2)->default(0);
            $t->unsignedInteger('kpi_score')->nullable();
            $t->decimal('kpi_incentive', 12, 2)->default(0);
            $t->decimal('bonus', 12, 2)->default(0);
            $t->decimal('late_deduction', 12, 2)->default(0);
            $t->decimal('absent_deduction', 12, 2)->default(0);
            $t->decimal('advance_deduction', 12, 2)->default(0);
            $t->decimal('gross', 12, 2);
            $t->decimal('total_deduction', 12, 2);
            $t->decimal('net', 12, 2);
            $t->string('payout_method')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('salary_advances', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 12, 2);
            $t->decimal('installment', 12, 2);
            $t->decimal('remaining', 12, 2);
            $t->string('reason')->nullable();
            $t->string('status')->default('PENDING'); // PENDING, APPROVED, PAID, REJECTED, CLOSED
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'salary_advances', 'payslips', 'payroll_runs', 'pos_payments', 'pos_order_lines', 'pos_orders', 'cashier_shifts', 'dining_tables',
            'catering_payments', 'catering_event_staff', 'catering_event_items', 'catering_events', 'vendor_payments', 'vendor_bills',
            'reimbursement_claims', 'wallet_transactions', 'purchase_assignment_lines', 'purchase_assignments', 'vendors', 'dispatches',
            'production_orders', 'standing_orders', 'requisition_histories', 'requisition_links', 'requisition_lines', 'requisitions',
            'recipe_lines', 'recipes', 'stock_counts', 'stock_movements', 'stocks', 'items', 'holidays', 'leave_requests', 'leave_types',
            'shift_swaps', 'attendance_corrections', 'proxy_grant_users', 'proxy_grants', 'attendances', 'rosters', 'shift_templates',
            'tasks', 'task_templates', 'approval_steps', 'approval_rules', 'expenses', 'journal_lines', 'journal_entries', 'fiscal_periods',
            'money_accounts', 'accounts', 'payment_methods', 'delegations', 'app_notifications', 'audit_logs', 'settings', 'feature_locations', 'features',
        ] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
