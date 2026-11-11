<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aylık tekrarlayan (çoğunlukla sabit) gider tanımları. Tutarlar kodda sabit değildir.
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('name', 120);
            $table->foreignId('category_id')->constrained('categories');
            $table->decimal('amount', 18, 2);             // beklenen aylık tutar
            $table->unsignedTinyInteger('day_of_month');  // 1-31; kısa aylarda ayın son günü
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->foreignId('account_id')->nullable()->constrained('accounts'); // varsayılan ödeme hesabı
            $table->string('counterparty', 150)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_day_check CHECK (day_of_month BETWEEN 1 AND 31)');
        DB::statement('ALTER TABLE recurring_expenses ADD CONSTRAINT recurring_expenses_dates_check CHECK (end_date IS NULL OR end_date >= start_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_expenses');
    }
};
