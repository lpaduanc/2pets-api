<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StoreCommissionRuleRequest;
use App\Http\Resources\Commercial\CommissionRuleResource;
use App\Models\CommissionRule;
use App\Models\CommissionRuleLog;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Cadastro de regra de comissão da clínica ao próprio staff — contrato
 * docs/gap-simplesvet/specs/09-comissionamento-interno-repasses-spec.md.
 *
 * Só `clinic_owner`/`petshop_owner` gerencia (`CommissionRulePolicy`) — comissão é dinheiro
 * real do funcionário, mesma régua de `FinancialAccountController`.
 */
class CommissionRuleController extends Controller
{
    /** @var list<string> */
    private const AUDITED_FIELDS = ['staff_id', 'scope', 'scope_id', 'percent', 'fixed_amount', 'calculation_base', 'active'];

    public function __construct(private readonly CommercialScopeResolver $scope) {}

    /**
     * Listar percentuais cadastrados é informação de gestão (quanto CADA colega ganha por
     * escopo) — mesma régua do resto do módulo (regra de negócio 5): só o dono.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::forUser($request->user())->authorize('create', CommissionRule::class);

        $rules = $this->scopedQuery($request->user())
            ->with(CommissionRuleResource::RESOURCE_RELATIONS)
            ->orderBy('scope')
            ->get();

        return CommissionRuleResource::collection($rules);
    }

    public function store(StoreCommissionRuleRequest $request): JsonResponse
    {
        Gate::forUser($request->user())->authorize('create', CommissionRule::class);

        $rule = CommissionRule::create($request->validated() + $this->scope->ownershipFor($request->user()));

        return (new CommissionRuleResource($rule->load(CommissionRuleResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Regra de comissão cadastrada com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreCommissionRuleRequest $request, int $id): CommissionRuleResource
    {
        $rule = $this->scopedQuery($request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('manage', $rule);

        $this->logChanges($rule, $request->validated(), $request->user()->id);
        $rule->update($request->validated());

        return new CommissionRuleResource($rule->fresh(CommissionRuleResource::RESOURCE_RELATIONS));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $rule = $this->scopedQuery($request->user())->findOrFail($id);
        Gate::forUser($request->user())->authorize('manage', $rule);

        $rule->delete();

        return response()->json(['message' => 'Regra de comissão removida.']);
    }

    /**
     * @return Builder<CommissionRule>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(CommissionRule::query(), $user);
    }

    /**
     * Regra de negócio 6 do doc: toda mudança de valor gera log com o anterior e o novo, com
     * quem mudou. Sem isto, a palavra de um contra o outro é a única prova numa disputa.
     *
     * @param  array<string, mixed>  $incoming
     */
    private function logChanges(CommissionRule $rule, array $incoming, int $changedBy): void
    {
        foreach (self::AUDITED_FIELDS as $field) {
            if (! array_key_exists($field, $incoming)) {
                continue;
            }

            $old = $rule->getAttribute($field);
            $new = $incoming[$field];

            if ((string) $old === (string) $new) {
                continue;
            }

            CommissionRuleLog::create([
                'commission_rule_id' => $rule->id,
                'changed_by' => $changedBy,
                'field_changed' => $field,
                'old_value' => $old === null ? null : (string) $old,
                'new_value' => $new === null ? null : (string) $new,
            ]);
        }
    }
}
