<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Waiters for tables (one waiter many tables, many waiters one table): table calls and QR orders
 * become tasks for that table's waiters. QR menu payment: the customer pays by bKash / Nagad (QR set in
 * Company settings) and sends the transaction ID; the cashier checks it and closes the bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dining_table_waiter', function (Blueprint $t) {
            $t->id();
            $t->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['dining_table_id', 'user_id']);
        });

        Schema::table('pos_orders', function (Blueprint $t) {
            $t->string('pay_claim_method', 20)->nullable()->after('status'); // BKASH, NAGAD
            $t->string('pay_claim_trx', 40)->nullable()->after('pay_claim_method');
            $t->string('pay_claim_phone', 20)->nullable()->after('pay_claim_trx');
            $t->decimal('pay_claim_amount', 12, 2)->nullable()->after('pay_claim_phone');
            $t->timestamp('pay_claim_at')->nullable()->after('pay_claim_amount');
        });

        Schema::table('table_calls', function (Blueprint $t) {
            $t->foreignId('pos_order_id')->nullable()->after('dining_table_id')->constrained('pos_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('table_calls', function (Blueprint $t) {
            $t->dropConstrainedForeignId('pos_order_id');
        });
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->dropColumn(['pay_claim_method', 'pay_claim_trx', 'pay_claim_phone', 'pay_claim_amount', 'pay_claim_at']);
        });
        Schema::dropIfExists('dining_table_waiter');
    }
};
