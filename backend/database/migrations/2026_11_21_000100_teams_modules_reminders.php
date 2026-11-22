<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Module master switches: a feature works only when its module is ON.
        Schema::create('feature_modules', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();      // matches features.module
            $t->string('name_bn')->nullable();
            $t->string('description')->nullable();
            $t->string('status')->default('ON'); // ON, OFF
            $t->boolean('admin_access')->default(false); // company admins may use it (Company settings)
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });

        // Teams: a named group with a lead, optionally under a parent team.
        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('name_bn')->nullable();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('parent_id')->nullable()->constrained('teams')->nullOnDelete();
            $t->string('color')->default('orange');
            $t->string('description')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::table('users', function (Blueprint $t) {
            $t->foreignId('team_id')->nullable()->after('manager_id')->constrained('teams')->nullOnDelete();
        });

        Schema::table('tasks', function (Blueprint $t) {
            $t->foreignId('team_id')->nullable()->after('location_id')->constrained('teams')->nullOnDelete();
            $t->unsignedInteger('remind_before_minutes')->nullable()->after('due_at');
            $t->unsignedInteger('remind_every_minutes')->nullable()->after('remind_before_minutes');
            $t->dateTime('pre_reminded_at')->nullable()->after('remind_every_minutes');
            $t->dateTime('last_reminded_at')->nullable()->after('pre_reminded_at');
            $t->unsignedInteger('reminders_sent')->default(0)->after('last_reminded_at');
            $t->boolean('notify_on_start')->default(false)->after('reminders_sent');
            $t->boolean('notify_on_done')->default(true)->after('notify_on_start');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $t) {
            $t->dropConstrainedForeignId('team_id');
            $t->dropColumn(['remind_before_minutes', 'remind_every_minutes', 'pre_reminded_at', 'last_reminded_at', 'reminders_sent', 'notify_on_start', 'notify_on_done']);
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropConstrainedForeignId('team_id'));
        Schema::dropIfExists('teams');
        Schema::dropIfExists('feature_modules');
    }
};
