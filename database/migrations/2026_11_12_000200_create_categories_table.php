<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->default(1)->constrained();
            $table->foreignId('parent_id')->nullable()->constrained('categories');
            $table->string('code', 60);
            $table->string('name', 120);
            $table->string('direction', 10);   // inflow | outflow
            $table->string('activity', 15);    // operating | financing
            $table->string('cost_type', 10)->nullable(); // fixed | variable (yalnızca giderler)
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_direction_check CHECK (direction IN ('inflow', 'outflow'))");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_activity_check CHECK (activity IN ('operating', 'financing'))");
        DB::statement("ALTER TABLE categories ADD CONSTRAINT categories_cost_type_check CHECK (cost_type IS NULL OR cost_type IN ('fixed', 'variable'))");

        $this->seedReferenceCategories();
    }

    /**
     * PRD bölüm 8-11'deki standart kategoriler. Referans veri olduğu için
     * production'a da migration ile gelir.
     */
    private function seedReferenceCategories(): void
    {
        $tree = [
            'inflow' => [
                ['commission_income', 'Acente komisyon gelirleri', 'operating'],
                ['customer_collection', 'Müşteri tahsilatları', 'operating'],
                ['commission_share_in', 'Komisyon paylaşım girişleri', 'operating'],
                ['partner_capital', 'Ortak sermaye girişleri', 'financing'],
                ['loan_received', 'Alınan krediler', 'financing'],
                ['past_debt_collection', 'Geçmiş borç tahsilatları', 'operating'],
                ['other_inflow', 'Diğer nakit girişleri', 'operating'],
            ],
            'outflow' => [
                ['premium_transfer', 'Sigorta şirketlerine net prim transferleri', 'operating'],
                ['commission_share_out', 'Komisyon paylaşım ödemeleri', 'operating'],
                ['partner_payment', 'Ortaklara yapılan ödemeler', 'financing'],
                ['loan_installment', 'Kredi taksitleri', 'financing', null, [
                    ['loan_principal', 'Kredi anapara ödemesi'],
                    ['loan_interest', 'Kredi faiz ödemesi'],
                ]],
                ['past_debt_payment', 'Geçmiş borç ödemeleri', 'operating'],
                ['fixed_expense', 'Sabit giderler', 'operating', 'fixed', [
                    ['fx_rent', 'Ofis kirası'],
                    ['fx_building_fee', 'Bina aidatı'],
                    ['fx_accounting', 'Muhasebe giderleri'],
                    ['fx_financial_advisory', 'Mali müşavirlik'],
                    ['fx_legal', 'Hukuk giderleri'],
                    ['fx_software', 'Yazılım lisansları'],
                    ['fx_agency_system', 'Sigorta acente takip sistemi'],
                    ['fx_salaries', 'Personel maaşları'],
                    ['fx_sgk', 'SGK ödemeleri'],
                    ['fx_internet', 'İnternet'],
                    ['fx_phone', 'Telefon'],
                    ['fx_static_ip', 'Statik IP'],
                    ['fx_other', 'Diğer sabit giderler'],
                ]],
                ['variable_expense', 'Değişken giderler', 'operating', 'variable', [
                    ['vx_electricity', 'Elektrik'],
                    ['vx_water', 'Su'],
                    ['vx_natural_gas', 'Doğalgaz'],
                    ['vx_client_representation', 'Müşteri temsil giderleri'],
                    ['vx_hospitality', 'Ağırlama'],
                    ['vx_treats', 'İkram'],
                    ['vx_food', 'Gıda'],
                    ['vx_kitchen', 'Mutfak giderleri'],
                    ['vx_office_supplies', 'Ofis sarf malzemeleri'],
                    ['vx_transport', 'Ulaşım'],
                    ['vx_fuel', 'Akaryakıt'],
                    ['vx_vehicle', 'Araç giderleri'],
                    ['vx_marketing', 'Pazarlama'],
                    ['vx_advertising', 'Reklam'],
                    ['vx_digital_media', 'Dijital medya'],
                    ['vx_cargo', 'Kargo'],
                    ['vx_post', 'Posta'],
                    ['vx_stamp_tax', 'Damga vergisi'],
                    ['vx_fees', 'Harçlar'],
                    ['vx_other', 'Diğer değişken giderler'],
                ]],
                ['other_outflow', 'Diğer nakit çıkışları', 'operating'],
            ],
        ];

        $now = now();
        foreach ($tree as $direction => $roots) {
            foreach ($roots as $i => $root) {
                [$code, $name, $activity] = $root;
                $costType = $root[3] ?? null;
                $children = $root[4] ?? [];

                $parentId = DB::table('categories')->insertGetId([
                    'code' => $code, 'name' => $name, 'direction' => $direction, 'activity' => $activity,
                    'cost_type' => $costType, 'is_system' => true, 'sort' => ($i + 1) * 10,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                foreach ($children as $j => [$childCode, $childName]) {
                    DB::table('categories')->insert([
                        'parent_id' => $parentId, 'code' => $childCode, 'name' => $childName,
                        'direction' => $direction, 'activity' => $activity, 'cost_type' => $costType,
                        'is_system' => true, 'sort' => ($j + 1) * 10,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
