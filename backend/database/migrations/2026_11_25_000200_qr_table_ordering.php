<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** QR ordering: every table (and every branch) gets a secret QR code; customers order and call a waiter from their phone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $t) {
            $t->string('qr_token', 32)->nullable()->unique()->after('seats');
            $t->boolean('active')->default(true)->after('qr_token');
        });
        Schema::table('locations', function (Blueprint $t) {
            $t->string('qr_token', 32)->nullable()->unique()->after('delivery_rate');
        });
        Schema::create('table_calls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('location_id')->constrained()->cascadeOnDelete();
            $t->foreignId('dining_table_id')->constrained()->cascadeOnDelete();
            $t->string('type'); // WAITER, BILL, WATER, OTHER
            $t->string('note')->nullable();
            $t->string('status')->default('OPEN'); // OPEN, COMING, DONE
            $t->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('answered_at')->nullable();
            $t->dateTime('done_at')->nullable();
            $t->string('session', 64)->nullable(); // the customer's phone browser, to show them the answer
            $t->timestamps();
            $t->index(['location_id', 'status']);
        });
        foreach (DB::table('dining_tables')->whereNull('qr_token')->pluck('id') as $id) {
            DB::table('dining_tables')->where('id', $id)->update(['qr_token' => Str::lower(Str::random(12))]);
        }
        foreach (DB::table('locations')->where('type', 'BRANCH')->whereNull('qr_token')->pluck('id') as $id) {
            DB::table('locations')->where('id', $id)->update(['qr_token' => Str::lower(Str::random(12))]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('table_calls');
        Schema::table('locations', fn (Blueprint $t) => $t->dropColumn('qr_token'));
        Schema::table('dining_tables', fn (Blueprint $t) => $t->dropColumn(['qr_token', 'active']));
    }
};
