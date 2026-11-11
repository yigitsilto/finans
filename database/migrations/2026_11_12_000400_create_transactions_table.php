<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tek hareket defteri.
     *
     * - opening : açılış/devir bakiyesi      -> to_account
     * - inflow  : nakit girişi               -> to_account
     * - outflow : nakit çıkışı               -> from_account
     * - transfer: hesaplar arası (POS->banka) -> from_account + to_account (nakit akışını etkilemez)
     *
     * Kayıtlar silinmez. İptal, aynı tipte NEGATİF tutarlı bir ters kayıtla (reversal_of_id) yapılır;
     * böylece tüm toplamlar SUM(amount) ile doğru kalır ve geçmiş dönemler değişmez.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('type', 10);
            $table->date('date');
            $table->decimal('amount', 18, 2);
            $table->char('currency', 3)->default('TRY');
            $table->foreignId('from_account_id')->nullable()->constrained('accounts');
            $table->foreignId('to_account_id')->nullable()->constrained('accounts');
            $table->foreignId('category_id')->nullable()->constrained('categories');
            $table->string('counterparty', 150)->nullable(); // Sprint 4-5'te müşteri/ortak FK'leri eklenecek
            $table->string('reference_no', 100)->nullable();
            $table->text('description')->nullable();
            $table->date('pos_settlement_date')->nullable(); // POS girişinin bankaya geçeceği tarih
            $table->foreignId('settles_transaction_id')->nullable()->constrained('transactions'); // POS girişini kapatan transfer
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('transactions');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['date', 'type']);
            $table->index(['from_account_id', 'date']);
            $table->index(['to_account_id', 'date']);
            $table->index('category_id');
        });

        $checks = [
            'transactions_type_check' => "type IN ('opening', 'inflow', 'outflow', 'transfer')",
            // Normal kayıt pozitif, ters kayıt negatif tutarlıdır.
            'transactions_amount_sign_check' => '(reversal_of_id IS NULL AND amount > 0) OR (reversal_of_id IS NOT NULL AND amount < 0)',
            'transactions_accounts_check' => "(type IN ('opening', 'inflow') AND to_account_id IS NOT NULL AND from_account_id IS NULL)
                OR (type = 'outflow' AND from_account_id IS NOT NULL AND to_account_id IS NULL)
                OR (type = 'transfer' AND from_account_id IS NOT NULL AND to_account_id IS NOT NULL AND from_account_id <> to_account_id)",
            'transactions_category_check' => "(type IN ('inflow', 'outflow') AND category_id IS NOT NULL)
                OR (type IN ('opening', 'transfer') AND category_id IS NULL)",
            'transactions_pos_date_check' => "pos_settlement_date IS NULL OR type = 'inflow'",
            'transactions_settles_check' => "settles_transaction_id IS NULL OR type = 'transfer'",
        ];

        foreach ($checks as $name => $expression) {
            DB::statement("ALTER TABLE transactions ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
