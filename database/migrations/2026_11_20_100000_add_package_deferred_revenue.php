<?php

use App\Services\Commercial\Package\PackageSessionPricer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receita diferida de pacote (docs/gap-petflow/PLANO.md, 2.7).
 *
 * - `sold_package_sessions`: uma linha por sessão vendida, com o valor CONGELADO no ato da venda
 *   (`PackageSessionPricer`). `status`: pending · consumed · expired (virou receita de
 *   expiração) · credited (virou crédito na conta do cliente) · cancelled (venda cancelada).
 *   Na baixa guarda quem executou (`staff_id`, para a comissão da sessão) e o custo do serviço
 *   naquele dia (`unit_cost`, 1.3).
 * - `sold_packages`: preço líquido do pacote e soma avulsa congelados na venda, e a resolução do
 *   saldo vencido (política, data, valor, lançamento na conta do cliente).
 * - `sold_package_validity_extensions`: log da prorrogação (de/para, motivo, quem).
 * - `organizations.expired_package_policy` (client_credit padrão | expiration_revenue) e
 *   `package_expiring_soon_days` (limiar do status "À vencer", padrão 30).
 * - `appointments.sold_package_id`: o agendamento marcado para uma sessão do pacote (próximo /
 *   último agendamento do pacote).
 * - Comissão da sessão (2.6 + 2.7): `commission_settlement_items` e `commission_clawbacks`
 *   passam a apontar para uma sessão OU para um item de venda (exatamente um dos dois).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sold_package_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sold_package_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sold_package_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('pending');
            $table->foreignId('sold_package_consumption_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('consumed_at')->nullable();
            $table->foreignId('staff_id')->nullable()->constrained('organization_members')->nullOnDelete();
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['sold_package_item_id', 'sequence']);
            $table->index(['status', 'consumed_at']);
            $table->index(['staff_id', 'status']);
        });

        DB::statement("ALTER TABLE sold_package_sessions ADD CONSTRAINT sold_package_sessions_status_check CHECK (status IN ('pending', 'consumed', 'expired', 'credited', 'cancelled'))");

        Schema::table('sold_packages', function (Blueprint $table): void {
            $table->decimal('package_price', 14, 2)->nullable()->after('expires_at');
            $table->decimal('list_price_total', 14, 2)->nullable()->after('package_price');
            $table->string('expiration_resolution', 30)->nullable()->after('cancelled_at');
            $table->timestamp('expiration_resolved_at')->nullable()->after('expiration_resolution');
            $table->decimal('expiration_amount', 14, 2)->nullable()->after('expiration_resolved_at');
            $table->foreignId('client_account_entry_id')->nullable()->after('expiration_amount')->constrained()->nullOnDelete();
        });

        Schema::create('sold_package_validity_extensions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sold_package_id')->constrained()->cascadeOnDelete();
            $table->date('from_date')->nullable();
            $table->date('to_date');
            $table->string('reason', 500);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('sold_package_id');
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('expired_package_policy', 30)->default('client_credit');
            $table->unsignedSmallInteger('package_expiring_soon_days')->default(30);
        });

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_expired_package_policy_check CHECK (expired_package_policy IN ('client_credit', 'expiration_revenue'))");

        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('sold_package_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('commission_settlement_items', function (Blueprint $table): void {
            $table->foreignId('sale_item_id')->nullable()->change();
            $table->foreignId('sold_package_session_id')->nullable()->after('sale_item_id')->constrained()->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE commission_settlement_items ADD CONSTRAINT commission_settlement_items_source_check CHECK ((sale_item_id IS NULL) <> (sold_package_session_id IS NULL))');
        DB::statement('CREATE UNIQUE INDEX commission_settlement_items_active_session_unique ON commission_settlement_items (sold_package_session_id) WHERE reversed_at IS NULL AND sold_package_session_id IS NOT NULL');

        Schema::table('commission_clawbacks', function (Blueprint $table): void {
            $table->foreignId('sale_item_id')->nullable()->change();
            $table->foreignId('sold_package_session_id')->nullable()->after('sale_item_id')->constrained();
        });

        $this->backfillSessions();
    }

    /**
     * Pacote vendido antes desta migration ganha as sessões com o preço da LINHA da venda
     * (`sale_items.total` — sem o rateio do desconto do cabeçalho, que não ficou guardado) e o
     * preço avulso de HOJE dos serviços (o da época também não ficou). As consumidas são as
     * primeiras, na ordem das baixas. Aproximação documentada; pacote novo nasce exato.
     */
    private function backfillSessions(): void
    {
        $pricer = new PackageSessionPricer;

        DB::table('sold_packages')->join('sale_items', 'sale_items.id', '=', 'sold_packages.sale_item_id')
            ->select('sold_packages.id', 'sale_items.total')->orderBy('sold_packages.id')
            ->each(function (object $package) use ($pricer): void {
                $items = DB::table('sold_package_items')->join('services', 'services.id', '=', 'sold_package_items.service_id')
                    ->where('sold_package_id', $package->id)->orderBy('sold_package_items.id')
                    ->get(['sold_package_items.id', 'sold_package_items.service_id', 'sold_package_items.quantity_total', 'services.price']);
                $priced = $pricer->price((string) $package->total, $items->map(fn (object $item): array => [
                    'service_id' => (int) $item->service_id, 'unit_price' => (string) ($item->price ?? '0'), 'quantity' => (int) $item->quantity_total,
                ])->all());

                DB::table('sold_packages')->where('id', $package->id)->update(['package_price' => $priced['package_price'], 'list_price_total' => $priced['list_total']]);
                $items->values()->each(fn (object $item, int $index) => $this->backfillItem($package->id, $item->id, $priced['items'][$index]['sessions']));
            });
    }

    /** @param  list<string>  $sessions */
    private function backfillItem(int $packageId, int $itemId, array $sessions): void
    {
        $consumedAt = DB::table('sold_package_consumptions')->where('sold_package_item_id', $itemId)->orderBy('consumed_at')->orderBy('id')
            ->get(['id', 'quantity', 'consumed_at'])
            ->flatMap(fn (object $consumption): array => array_fill(0, (int) $consumption->quantity, [$consumption->id, $consumption->consumed_at]))
            ->values();

        foreach ($sessions as $index => $amount) {
            [$consumptionId, $at] = $consumedAt[$index] ?? [null, null];
            DB::table('sold_package_sessions')->insert([
                'sold_package_id' => $packageId, 'sold_package_item_id' => $itemId, 'sequence' => $index + 1, 'amount' => $amount,
                'status' => $at === null ? 'pending' : 'consumed', 'sold_package_consumption_id' => $consumptionId, 'consumed_at' => $at,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Linhas de comissão de sessão não têm para onde voltar no modelo antigo (item de venda
        // obrigatório): saem antes de o NOT NULL voltar.
        DB::table('commission_settlement_deductions')
            ->whereIn('commission_clawback_id', DB::table('commission_clawbacks')->whereNotNull('sold_package_session_id')->select('id'))
            ->delete();
        DB::table('commission_clawbacks')->whereNotNull('sold_package_session_id')->delete();
        DB::table('commission_settlement_items')->whereNotNull('sold_package_session_id')->delete();

        Schema::table('commission_clawbacks', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sold_package_session_id');
            $table->foreignId('sale_item_id')->nullable(false)->change();
        });

        DB::statement('DROP INDEX IF EXISTS commission_settlement_items_active_session_unique');
        DB::statement('ALTER TABLE commission_settlement_items DROP CONSTRAINT IF EXISTS commission_settlement_items_source_check');

        Schema::table('commission_settlement_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sold_package_session_id');
            $table->foreignId('sale_item_id')->nullable(false)->change();
        });

        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sold_package_id');
        });

        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_expired_package_policy_check');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn(['expired_package_policy', 'package_expiring_soon_days']);
        });

        Schema::dropIfExists('sold_package_validity_extensions');

        Schema::table('sold_packages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('client_account_entry_id');
            $table->dropColumn(['package_price', 'list_price_total', 'expiration_resolution', 'expiration_resolved_at', 'expiration_amount']);
        });

        Schema::dropIfExists('sold_package_sessions');
    }
};
