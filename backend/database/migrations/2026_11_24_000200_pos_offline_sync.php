<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Offline POS: bills made without network keep their device id + offline bill number, and when they synced. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->string('client_uuid', 64)->nullable()->unique()->after('number');
            $t->string('offline_ref', 40)->nullable()->after('client_uuid');
            $t->dateTime('synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pos_orders', function (Blueprint $t) {
            $t->dropUnique(['client_uuid']);
            $t->dropColumn(['client_uuid', 'offline_ref', 'synced_at']);
        });
    }
};
