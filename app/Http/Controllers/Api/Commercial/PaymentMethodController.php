<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\PaymentDirection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\StorePaymentMethodRequest;
use App\Http\Resources\Commercial\PaymentMethodResource;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Commercial\CommercialScopeResolver;
use App\Services\Commercial\PaymentMethodProvisioner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Formas de recebimento da clínica — o que o modal de recebimento do PDV, a conferência do
 * caixa (docs/gap-simplesvet/01-caixa-pdv.md) e a conversão de orçamento (doc 24) listam. O
 * cadastro completo (adquirente, taxa, conta destino, conciliação) é do doc 04; este controller
 * já aceita esses campos para que aquela tela não precise de outra rota.
 *
 * Sem `destroy`: forma usada em recebimento é histórico. Desativa-se (`active = false`).
 */
class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly PaymentMethodProvisioner $provisioner,
    ) {}

    /**
     * Só as ATIVAS por padrão — é o que todo modal de recebimento quer. `active=0` lista as
     * desativadas e `include_inactive=1` lista todas (tela de cadastro do doc 04).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'direction' => ['nullable', Rule::enum(PaymentDirection::class)],
            'active' => ['nullable', 'boolean'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $this->provisioner->ensureDefaults($user);

        $methods = $this->scopedQuery($user)
            ->with('defaultAccount:id,name')
            ->when($request->filled('direction'), fn (Builder $q) => $q->forDirection(
                PaymentDirection::from($request->string('direction')->toString())
            ))
            ->when(! $request->boolean('include_inactive'), fn (Builder $q) => $q->where(
                'active',
                $request->has('active') ? $request->boolean('active') : true
            ))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get();

        return PaymentMethodResource::collection($methods);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertAccountInScope($request->user(), $data['default_account_id'] ?? null);

        $method = PaymentMethod::create($data + $this->scope->ownershipFor($request->user()));

        return (new PaymentMethodResource($method->load('defaultAccount:id,name')))
            ->additional(['message' => 'Forma de recebimento cadastrada.'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(StorePaymentMethodRequest $request, int $id): PaymentMethodResource
    {
        $method = $this->scopedQuery($request->user())->findOrFail($id);
        $data = $request->validated();
        $this->assertAccountInScope($request->user(), $data['default_account_id'] ?? null);

        $method->update($data);

        return new PaymentMethodResource($method->fresh('defaultAccount:id,name'));
    }

    /**
     * @return Builder<PaymentMethod>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(PaymentMethod::query(), $user);
    }

    /** Conta destino de outra clínica faria o recebimento "depositar" no extrato dela. */
    private function assertAccountInScope(User $user, mixed $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        if (! $this->scope->scopeQuery(FinancialAccount::query(), $user)->whereKey((int) $accountId)->exists()) {
            throw ValidationException::withMessages(['default_account_id' => 'Conta não encontrada nesta clínica.']);
        }
    }
}
