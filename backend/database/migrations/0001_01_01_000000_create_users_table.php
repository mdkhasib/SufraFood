<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('type'); // OFFICE, WAREHOUSE, CENTRAL_KITCHEN, CATERING, BRANCH
            $table->string('address')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('radius_m')->default(80);
            $table->unsignedBigInteger('manager_id')->nullable(); // location head (users.id)
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('tier')->default('T1'); // T1..T4, FUNCTION, OFFICE
            $table->string('scope')->default('SELF'); // ALL, LOCATION, TEAM, SELF
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->unique(['role_id', 'permission']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code')->unique();
            $table->string('name');
            $table->string('name_bn')->nullable();
            $table->string('phone')->unique();
            $table->string('email')->nullable()->unique();
            $table->string('password');
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('position')->nullable();
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('is_company_admin')->default(false);
            $table->string('language', 2)->default('en');
            $table->string('status')->default('ACTIVE');
            $table->date('joined_at')->nullable();
            $table->string('employment_type')->default('PERMANENT');
            // salary structure
            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('house_rent', 12, 2)->default(0);
            $table->decimal('medical', 12, 2)->default(0);
            $table->decimal('conveyance', 12, 2)->default(0);
            $table->string('payout_method')->default('CASH'); // CASH, BANK, MFS
            $table->string('payout_account')->nullable();
            // personal limits
            $table->decimal('approval_limit', 14, 2)->nullable();
            $table->decimal('advance_limit', 14, 2)->nullable();
            $table->decimal('own_money_limit', 14, 2)->nullable();
            $table->decimal('discount_limit', 5, 2)->nullable();
            $table->string('device_id')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('locations');
    }
};
