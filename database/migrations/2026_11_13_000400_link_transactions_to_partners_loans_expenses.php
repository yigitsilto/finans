<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Kategorinin hangi kayda bağlanmayı zorunlu kıldığı: partner | loan | null
        Schema::table('categories', function (Blueprint $table) {
            $table->string('link_type', 10)->nullable()->after('cost_type');
        });
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_link_type_check CHECK (link_type IS NULL OR link_type IN ('partner', 'loan'))");

        $now = now();
        $sort = fn (string $direction) => (int) DB::table('categories')->where('direction', $direction)->whereNull('parent_id')->max('sort');
        DB::table('categories')->insert([
            ['code' => 'partner_loan_received', 'name' => 'Ortaktan alınan borç', 'direction' => 'inflow', 'activity' => 'financing',
                'is_system' => true, 'sort' => $sort('inflow') + 10, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'partner_loan_repayment', 'name' => 'Ortağa borç geri ödemesi', 'direction' => 'outflow', 'activity' => 'financing',
                'is_system' => true, 'sort' => $sort('outflow') + 10, 'created_at' => $now, 'updated_at' => $now],
        ]);

        DB::table('categories')->whereIn('code', ['partner_capital', 'partner_payment', 'partner_loan_received', 'partner_loan_repayment'])
            ->update(['link_type' => 'partner']);
        DB::table('categories')->whereIn('code', ['loan_received', 'loan_principal', 'loan_interest'])
            ->update(['link_type' => 'loan']);

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('category_id')->constrained('partners');
            $table->foreignId('loan_id')->nullable()->after('partner_id')->constrained('loans');
            $table->foreignId('loan_installment_id')->nullable()->after('loan_id')->constrained('loan_installments');
            $table->foreignId('recurring_expense_id')->nullable()->after('loan_installment_id')->constrained('recurring_expenses');
            $table->date('expense_period')->nullable()->after('recurring_expense_id'); // ayın ilk günü

            $table->index('partner_id');
            $table->index('loan_id');
            $table->index('loan_installment_id');
            $table->index(['recurring_expense_id', 'expense_period']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_installment_check CHECK (loan_installment_id IS NULL OR loan_id IS NOT NULL)');
        DB::statement('ALTER TABLE transactions ADD CONSTRAINT transactions_expense_period_check CHECK ((recurring_expense_id IS NULL) = (expense_period IS NULL))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_installment_check');
        DB::statement('ALTER TABLE transactions DROP CONSTRAINT IF EXISTS transactions_expense_period_check');
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_id');
            $table->dropConstrainedForeignId('loan_id');
            $table->dropConstrainedForeignId('loan_installment_id');
            $table->dropConstrainedForeignId('recurring_expense_id');
            $table->dropColumn('expense_period');
        });
        DB::table('categories')->whereIn('code', ['partner_loan_received', 'partner_loan_repayment'])->delete();
        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('link_type'));
    }
};
