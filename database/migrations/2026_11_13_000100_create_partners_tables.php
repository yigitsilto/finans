<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('name', 120);
            $table->decimal('share_percent', 5, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->unique(['company_id', 'name']);
        });

        DB::statement('ALTER TABLE partners ADD CONSTRAINT partners_share_check CHECK (share_percent IS NULL OR (share_percent > 0 AND share_percent <= 100))');

        /*
         * Nakit hareketi OLMAYAN cari kayıtlar (ör. ortağın şirket adına cebinden ödediği gider).
         * credit = şirketin ortağa borcu artar, debit = azalır. Nakit akış raporuna girmez.
         * İptal, negatif tutarlı ters kayıtla yapılır.
         */
        Schema::create('partner_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained();
            $table->date('date');
            $table->string('direction', 6); // credit | debit
            $table->decimal('amount', 18, 2);
            $table->text('description');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('partner_adjustments');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['partner_id', 'date']);
        });

        DB::statement("ALTER TABLE partner_adjustments ADD CONSTRAINT partner_adjustments_direction_check CHECK (direction IN ('credit', 'debit'))");
        DB::statement('ALTER TABLE partner_adjustments ADD CONSTRAINT partner_adjustments_amount_check CHECK ((reversal_of_id IS NULL AND amount > 0) OR (reversal_of_id IS NOT NULL AND amount < 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_adjustments');
        Schema::dropIfExists('partners');
    }
};
