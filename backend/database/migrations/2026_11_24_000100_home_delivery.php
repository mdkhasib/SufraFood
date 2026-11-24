<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Home delivery: delivery details on POS orders, rider + per-branch rate, delivery bonus on payslips. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->string('source')->default('POS')->after('order_type'); // POS (branch counter) | ONLINE (online / call-centre team)
            $t->string('customer_name')->nullable()->after('customer_phone');
            $t->text('delivery_address')->nullable()->after('customer_name');
            $t->string('delivery_area')->nullable()->after('delivery_address');
            $t->string('delivery_note')->nullable()->after('delivery_area');
            $t->string('payment_mode')->nullable()->after('delivery_note'); // COD | PREPAID
            $t->string('delivery_status')->nullable()->after('payment_mode'); // COOKING, READY, PICKED_UP, DELIVERED, CANCELLED
            $t->foreignId('rider_id')->nullable()->after('delivery_status')->constrained('users')->nullOnDelete();
            $t->foreignId('rider_assigned_by')->nullable()->after('rider_id')->constrained('users')->nullOnDelete();
            $t->unsignedBigInteger('delivery_task_id')->nullable()->after('rider_assigned_by');
            $t->dateTime('rider_assigned_at')->nullable();
            $t->dateTime('ready_at')->nullable();
            $t->dateTime('picked_up_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->decimal('rider_fee', 10, 2)->default(0);
            $t->decimal('cash_collected', 12, 2)->nullable();
            $t->index(['delivery_status', 'location_id']);
            $t->index(['rider_id', 'delivered_at']);
        });
        Schema::table('locations', function (Blueprint $t) {
            $t->decimal('delivery_rate', 10, 2)->nullable()->after('phone'); // ৳ per delivery for the rider; null = company default
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('can_deliver')->default(false)->after('position');
        });
        Schema::table('payslips', function (Blueprint $t) {
            $t->unsignedInteger('delivery_count')->default(0)->after('kpi_incentive');
            $t->decimal('delivery_bonus', 12, 2)->default(0)->after('delivery_count');
        });
    }

    public function down(): void
    {
        Schema::table('payslips', fn (Blueprint $t) => $t->dropColumn(['delivery_count', 'delivery_bonus']));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('can_deliver'));
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn('delivery_rate'));
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->dropIndex(['delivery_status', 'location_id']);
            $t->dropIndex(['rider_id', 'delivered_at']);
            $t->dropConstrainedForeignId('rider_id');
            $t->dropConstrainedForeignId('rider_assigned_by');
            $t->dropColumn(['source', 'customer_name', 'delivery_address', 'delivery_area', 'delivery_note', 'payment_mode', 'delivery_status', 'delivery_task_id',
                'rider_assigned_at', 'ready_at', 'picked_up_at', 'delivered_at', 'rider_fee', 'cash_collected']);
        });
    }
};
