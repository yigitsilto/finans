<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->after('name');
            $table->string('email')->nullable()->change();
            $table->string('role', 20)->default('viewer')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
            $table->timestamp('last_login_at')->nullable();
        });

        DB::table('users')->whereNull('username')->update(['username' => DB::raw("CONCAT('user', id)")]);
        Schema::table('users', fn (Blueprint $table) => $table->string('username', 50)->nullable(false)->change());

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (LOWER(username))');
        } else {
            // MariaDB: utf8mb4_unicode_ci karşılaştırması zaten büyük/küçük harf duyarsız.
            Schema::table('users', fn (Blueprint $table) => $table->unique('username', 'users_username_lower_unique'));
        }
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('admin', 'finance', 'viewer'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
            DB::statement('DROP INDEX IF EXISTS users_username_lower_unique');
        } else {
            DB::statement('ALTER TABLE users DROP CONSTRAINT users_role_check');
            Schema::table('users', fn (Blueprint $table) => $table->dropUnique('users_username_lower_unique'));
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'is_active', 'last_login_at']);
        });
    }
};
