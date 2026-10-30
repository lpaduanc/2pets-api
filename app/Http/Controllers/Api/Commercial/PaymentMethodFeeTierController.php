<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\UpdatePaymentFeeTiersRequest;
use App\Http\Resources\Commercial\PaymentFeeTierResource;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Commercial\Card\FeeTierService;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Faixas de taxa por bandeira e parcela de uma forma de cartão — docs/gap-petflow/PLANO.md, 1.1.
 * A forma é sempre lida pela query escopada: faixa de outra clínica nunca aparece nem é editada.
 */
class PaymentMethodFeeTierController extends Controller
{
    public function __construct(
        private readonly CommercialScopeResolver $scope,
        private readonly FeeTierService $tiers,
    ) {}

    public function index(Request $request, int $id): AnonymousResourceCollection
    {
        return PaymentFeeTierResource::collection($this->method($request->user(), $id)->feeTiers);
    }

    public function update(UpdatePaymentFeeTiersRequest $request, int $id): AnonymousResourceCollection
    {
        $method = $this->tiers->replace($this->method($request->user(), $id), $request->tiers());

        return PaymentFeeTierResource::collection($method->feeTiers)
            ->additional(['message' => 'Faixas de taxa salvas.']);
    }

    private function method(User $user, int $id): PaymentMethod
    {
        $method = $this->scope->scopeQuery(PaymentMethod::query(), $user)->findOrFail($id);
        abort_unless($method->kind->settlesThroughAcquirer(), 422, 'Faixas de taxa só existem para formas de cartão.');

        return $method;
    }
}
