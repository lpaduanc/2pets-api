<?php

namespace App\Services\Finance;

use App\Enums\FinancialCategoryKind;
use App\Enums\FinancialNature;
use App\Models\FinancialCategory;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Plano-padrão brasileiro para clínica/petshop — contrato
 * docs/gap-simplesvet/02-financeiro-plano-de-contas-dre.md.
 *
 * Idempotente por escopo, mesmo padrão de `PaymentMethodProvisioner`: só semeia quando o
 * escopo não tem NENHUMA categoria (nem inativa/apagada), para não ressuscitar uma árvore que
 * a clínica já reorganizou.
 *
 * Opera por OWNERSHIP (`organization_id`/`professional_id`), não por `User` corrente, porque
 * a categoria do sistema também nasce a partir de um lançamento automático sem usuário logado
 * (fatura paga por webhook de gateway) — ver `InvoiceFinancialEntryRecorder`.
 */
final class FinancialCategoryProvisioner
{
    /**
     * @var list<array{name: string, nature: FinancialNature, children: list<string>}>
     */
    private const DEFAULTS = [
        ['name' => 'Receita de vendas', 'nature' => FinancialNature::REVENUE, 'children' => ['Produtos', 'Serviços']],
        ['name' => 'Taxas e tarifas', 'nature' => FinancialNature::EXPENSE, 'children' => ['Taxas de cartão']],
        ['name' => 'Custos e despesas', 'nature' => FinancialNature::EXPENSE, 'children' => ['Fornecedores', 'Despesas operacionais']],
    ];

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    public function ensureDefaults(User $user): void
    {
        $this->ensureDefaultsFor($this->scope->ownershipFor($user));
    }

    /** @param  array{organization_id: int|null, professional_id: int}  $ownership */
    public function ensureDefaultsFor(array $ownership): void
    {
        if ($this->scopedCategories($ownership)->withTrashed()->exists()) {
            return;
        }

        DB::transaction(function () use ($ownership): void {
            foreach (self::DEFAULTS as $order => $group) {
                $parent = FinancialCategory::create([
                    'name' => $group['name'],
                    'nature' => $group['nature']->value,
                    'kind' => FinancialCategoryKind::GROUP->value,
                    'is_system' => true,
                    'sort_order' => $order,
                    'active' => true,
                ] + $ownership);

                foreach ($group['children'] as $childOrder => $childName) {
                    FinancialCategory::create([
                        'parent_id' => $parent->id,
                        'name' => $childName,
                        'nature' => $group['nature']->value,
                        'kind' => FinancialCategoryKind::ENTRY->value,
                        'is_system' => true,
                        'sort_order' => $childOrder,
                        'active' => true,
                    ] + $ownership);
                }
            }
        });
    }

    /** Categoria-folha do sistema, criada sob demanda pelas integrações automáticas. */
    public function systemCategory(User $user, FinancialNature $nature, string $name, ?string $parentName = null): FinancialCategory
    {
        return $this->systemCategoryFor($this->scope->ownershipFor($user), $nature, $name, $parentName);
    }

    /** @param  array{organization_id: int|null, professional_id: int}  $ownership */
    public function systemCategoryFor(array $ownership, FinancialNature $nature, string $name, ?string $parentName = null): FinancialCategory
    {
        $this->ensureDefaultsFor($ownership);

        $existing = $this->scopedCategories($ownership)
            ->where('nature', $nature->value)
            ->where('kind', FinancialCategoryKind::ENTRY->value)
            ->where('name', $name)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $parentId = $parentName === null ? null : $this->scopedCategories($ownership)
            ->where('nature', $nature->value)
            ->where('kind', FinancialCategoryKind::GROUP->value)
            ->where('name', $parentName)
            ->value('id');

        return FinancialCategory::create([
            'parent_id' => $parentId,
            'name' => $name,
            'nature' => $nature->value,
            'kind' => FinancialCategoryKind::ENTRY->value,
            'is_system' => true,
            'sort_order' => 99,
            'active' => true,
        ] + $ownership);
    }

    /**
     * @param  array{organization_id: int|null, professional_id: int}  $ownership
     * @return Builder<FinancialCategory>
     */
    private function scopedCategories(array $ownership): Builder
    {
        $query = FinancialCategory::query();

        return $ownership['organization_id'] !== null
            ? $query->where('organization_id', $ownership['organization_id'])
            : $query->where('professional_id', $ownership['professional_id'])->whereNull('organization_id');
    }
}
