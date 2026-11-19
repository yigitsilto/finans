<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->string('type', 12)->default('individual'); // individual | corporate
            $table->string('name', 150);
            $table->text('tax_number')->nullable();            // TCKN / VKN, şifreli
            $table->string('phone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('name');
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_type_check CHECK (type IN ('individual', 'corporate'))");

        /*
         * Müşteri alacağı. Tahsil edilen ve kalan tutar SAKLANMAZ; bağlı nakit girişlerinden hesaplanır.
         * Silinmez; tahsilatı olmayan alacak iptal edilebilir (cancelled_at).
         */
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->string('reference_no', 100);               // poliçe veya referans no
            $table->string('insurance_company', 120)->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 18, 2);
            $table->date('issue_date');
            $table->date('due_date');
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['due_date']);
            $table->index(['customer_id']);
            $table->index('reference_no');
        });
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT receivables_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT receivables_dates_check CHECK (due_date >= issue_date)');
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT receivables_cancel_check CHECK ((cancelled_at IS NULL) = (cancel_reason IS NULL))');

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('receivable_id')->nullable()->after('expense_period')->constrained('receivables');
            $table->index('receivable_id');
        });

        /*
         * Veritabanı seviyesinde fazla tahsilat koruması (uygulama atlanırsa bile):
         * - Alacağa yalnızca nakit girişi bağlanabilir.
         * - İptal edilmiş alacağa tahsilat yazılamaz.
         * - Tahsilat toplamı 0 ile alacak tutarı arasında kalmalıdır.
         * Alacak satırı FOR UPDATE kilitlenir, eşzamanlı tahsilatlar sıralanır.
         */
        if (DB::getDriverName() === 'pgsql') {
            $this->postgresTriggers();
        } else {
            $this->mariadbTriggers();
        }
    }

    private function postgresTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION check_receivable_collection() RETURNS trigger AS $$
            DECLARE
                total NUMERIC(18,2);
                cancelled TIMESTAMP;
                collected NUMERIC(18,2);
            BEGIN
                IF NEW.receivable_id IS NULL THEN
                    RETURN NEW;
                END IF;

                SELECT amount, cancelled_at INTO total, cancelled FROM receivables WHERE id = NEW.receivable_id FOR UPDATE;

                IF NEW.type <> 'inflow' THEN
                    RAISE EXCEPTION 'Alacak tahsilatı yalnızca nakit girişi olabilir' USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.amount > 0 AND cancelled IS NOT NULL THEN
                    RAISE EXCEPTION 'İptal edilmiş alacağa tahsilat yazılamaz' USING ERRCODE = 'check_violation';
                END IF;

                SELECT COALESCE(SUM(amount), 0) INTO collected FROM transactions WHERE receivable_id = NEW.receivable_id;

                IF collected > total THEN
                    RAISE EXCEPTION 'Tahsilat toplamı (%) alacak tutarını (%) aşamaz', collected, total USING ERRCODE = 'check_violation';
                END IF;
                IF collected < 0 THEN
                    RAISE EXCEPTION 'Tahsilat toplamı negatif olamaz' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER transactions_receivable_collection
                AFTER INSERT OR UPDATE OF amount, receivable_id, type ON transactions
                FOR EACH ROW EXECUTE FUNCTION check_receivable_collection();

            CREATE OR REPLACE FUNCTION check_receivable_update() RETURNS trigger AS $$
            DECLARE
                collected NUMERIC(18,2);
            BEGIN
                SELECT COALESCE(SUM(amount), 0) INTO collected FROM transactions WHERE receivable_id = NEW.id;

                IF NEW.amount < collected THEN
                    RAISE EXCEPTION 'Alacak tutarı tahsil edilen tutarın (%) altına düşürülemez', collected USING ERRCODE = 'check_violation';
                END IF;
                IF NEW.cancelled_at IS NOT NULL AND collected <> 0 THEN
                    RAISE EXCEPTION 'Tahsilatı olan alacak iptal edilemez' USING ERRCODE = 'check_violation';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER receivables_amount_guard
                BEFORE UPDATE OF amount, cancelled_at ON receivables
                FOR EACH ROW EXECUTE FUNCTION check_receivable_update();
        SQL);
    }

    /**
     * MariaDB 10.11 karşılığı. Her olay için ayrı trigger gerekir; gövde aynı kural setini uygular.
     * Mesajlar PostgreSQL sürümüyle aynıdır.
     */
    private function mariadbTriggers(): void
    {
        $collectionCheck = <<<'SQL'
            BEGIN
                DECLARE v_total DECIMAL(18,2);
                DECLARE v_cancelled TIMESTAMP DEFAULT NULL;
                DECLARE v_collected DECIMAL(18,2);
                DECLARE v_message VARCHAR(255);

                IF NEW.receivable_id IS NOT NULL THEN
                    SELECT amount, cancelled_at INTO v_total, v_cancelled FROM receivables WHERE id = NEW.receivable_id FOR UPDATE;

                    IF NEW.type <> 'inflow' THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Alacak tahsilatı yalnızca nakit girişi olabilir';
                    END IF;
                    IF NEW.amount > 0 AND v_cancelled IS NOT NULL THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'İptal edilmiş alacağa tahsilat yazılamaz';
                    END IF;

                    SELECT COALESCE(SUM(amount), 0) INTO v_collected FROM transactions WHERE receivable_id = NEW.receivable_id;

                    IF v_collected > v_total THEN
                        SET v_message = CONCAT('Tahsilat toplamı (', v_collected, ') alacak tutarını (', v_total, ') aşamaz');
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_message;
                    END IF;
                    IF v_collected < 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tahsilat toplamı negatif olamaz';
                    END IF;
                END IF;
            END
        SQL;

        DB::unprepared("CREATE TRIGGER transactions_receivable_collection_ai AFTER INSERT ON transactions FOR EACH ROW {$collectionCheck}");
        DB::unprepared("CREATE TRIGGER transactions_receivable_collection_au AFTER UPDATE ON transactions FOR EACH ROW {$collectionCheck}");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER receivables_amount_guard BEFORE UPDATE ON receivables FOR EACH ROW
            BEGIN
                DECLARE v_collected DECIMAL(18,2);
                DECLARE v_message VARCHAR(255);

                SELECT COALESCE(SUM(amount), 0) INTO v_collected FROM transactions WHERE receivable_id = NEW.id;

                IF NEW.amount < v_collected THEN
                    SET v_message = CONCAT('Alacak tutarı tahsil edilen tutarın (', v_collected, ') altına düşürülemez');
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = v_message;
                END IF;
                IF NEW.cancelled_at IS NOT NULL AND v_collected <> 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Tahsilatı olan alacak iptal edilemez';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS transactions_receivable_collection ON transactions');
            DB::unprepared('DROP FUNCTION IF EXISTS check_receivable_collection()');
            DB::unprepared('DROP TRIGGER IF EXISTS receivables_amount_guard ON receivables');
            DB::unprepared('DROP FUNCTION IF EXISTS check_receivable_update()');
        } else {
            DB::unprepared('DROP TRIGGER IF EXISTS transactions_receivable_collection_ai');
            DB::unprepared('DROP TRIGGER IF EXISTS transactions_receivable_collection_au');
            DB::unprepared('DROP TRIGGER IF EXISTS receivables_amount_guard');
        }
        Schema::table('transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('receivable_id'));
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('customers');
    }
};
