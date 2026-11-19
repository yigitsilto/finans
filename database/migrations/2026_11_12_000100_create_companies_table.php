<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Şimdilik tek şirket; tablolar çoklu şirkete hazır olsun diye company_id taşır.
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('base_currency', 3)->default('TRY');
            $table->timestamps();
        });

        DB::table('companies')->insert([
            'id' => 1,
            'name' => 'Zafir Sigorta Aracılık Hizmetleri',
            'base_currency' => 'TRY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval('companies_id_seq', 1)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
