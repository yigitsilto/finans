<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bakiye bu tabloda tutulmaz; her zaman transactions tablosundan hesaplanır.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('type', 10);                 // cash | bank | pos
            $table->string('name', 120);
            $table->string('bank_name', 120)->nullable();
            $table->text('iban')->nullable();           // şifreli (encrypted cast)
            $table->char('currency', 3)->default('TRY');
            $table->foreignId('settlement_account_id')->nullable()->constrained('accounts'); // POS'un geçeceği banka
            $table->boolean('allow_negative')->default(false); // KMH vb. için
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (type IN ('cash', 'bank', 'pos'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_settlement_check CHECK (settlement_account_id IS NULL OR type = 'pos')");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
