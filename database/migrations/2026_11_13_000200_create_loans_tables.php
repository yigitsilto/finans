<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('name', 120);
            $table->string('bank_name', 120);
            $table->foreignId('account_id')->constrained('accounts'); // kullandırım/ödeme hesabı
            $table->date('start_date');
            $table->decimal('principal', 18, 2);
            $table->decimal('annual_interest_rate', 9, 4);            // yıllık %, ör. 42.0000
            $table->unsignedSmallInteger('term_months');
            $table->date('first_due_date');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_principal_check CHECK (principal > 0)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_rate_check CHECK (annual_interest_rate >= 0 AND annual_interest_rate < 1000)');
        DB::statement('ALTER TABLE loans ADD CONSTRAINT loans_term_check CHECK (term_months BETWEEN 1 AND 360)');

        // Taksit planı. Ödeme durumu, bağlı (iptal edilmemiş) transactions kayıtlarından hesaplanır.
        Schema::create('loan_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('principal', 18, 2);
            $table->decimal('interest', 18, 2); // faiz + BSMV/KKDF gibi finansman maliyetleri
            $table->timestamps();

            $table->unique(['loan_id', 'number']);
        });

        DB::statement('ALTER TABLE loan_installments ADD COLUMN total NUMERIC(18,2) GENERATED ALWAYS AS (principal + interest) STORED');
        DB::statement('ALTER TABLE loan_installments ADD CONSTRAINT loan_installments_amount_check CHECK (principal >= 0 AND interest >= 0 AND principal + interest > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_installments');
        Schema::dropIfExists('loans');
    }
};
