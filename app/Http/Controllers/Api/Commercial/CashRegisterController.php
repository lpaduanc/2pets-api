<?php

namespace App\Http\Controllers\Api\Commercial;

use App\Enums\CashMovementType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Commercial\CloseCashRegisterRequest;
use App\Http\Requests\Commercial\OpenCashRegisterRequest;
use App\Http\Requests\Commercial\StoreCashMovementRequest;
use App\Http\Resources\Commercial\CashRegisterMovementResource;
use App\Http\Resources\Commercial\CashRegisterResource;
use App\Models\CashRegister;
use App\Models\FinancialAccount;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Commercial\CashRegisterService;
use App\Services\Commercial\CommercialScopeResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Caixa — contrato docs/gap-simplesvet/01-caixa-pdv.md.
 *
 * Toda mutação delega a `CashRegisterService`; toda autorização passa por `CashRegisterPolicy`.
 * O controller só traduz HTTP: nenhuma regra de "pode fechar?" ou "aceita movimento?" mora aqui.
 */
class CashRegisterController extends Controller
{
    public function __construct(
        private readonly CashRegisterService $cashRegisters,
        private readonly CommercialScopeResolver $scope,
    ) {}

    /**
     * "Meus caixas" e "Outros caixas" do SimplesVet, mais a navegação dia-a-dia. Um único
     * endpoint com filtro `scope=mine|others|all`, porque as três listas têm exatamente as
     * mesmas colunas — separar em rotas duplicaria a montagem da resposta.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->scopedQuery($request->user())
            ->with(CashRegisterResource::RESOURCE_RELATIONS);

        match ($request->string('scope', 'all')->toString()) {
            'mine' => $query->where('opened_by', $request->user()->id),
            'others' => $query->where('opened_by', '!=', $request->user()->id),
            default => null,
        };

        if ($request->filled('date')) {
            $query->onDay($request->date('date'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        $registers = $query->orderByDesc('opened_at')->limit(100)->get();

        return CashRegisterResource::collection($registers);
    }

    /**
     * O caixa aberto de quem chamou. Devolve 200 com `data: null` em vez de 404: "não tenho
     * caixa aberto" é uma resposta normal que o PDV consulta a cada carga de tela, e um 404
     * poluiria o console e o monitoramento de erro do app com uma condição esperada.
     */
    public function current(Request $request): JsonResponse
    {
        $register = $this->cashRegisters->currentFor($request->user());

        return response()->json([
            'data' => $register === null
                ? null
                : new CashRegisterResource($register->load(CashRegisterResource::RESOURCE_RELATIONS)),
        ]);
    }

    public function store(OpenCashRegisterRequest $request): JsonResponse
    {
        $register = $this->cashRegisters->open(
            $request->user(),
            (float) $request->input('opening_amount', 0),
            $request->input('name'),
            $request->input('notes'),
        );

        return (new CashRegisterResource($register->load(CashRegisterResource::RESOURCE_RELATIONS)))
            ->additional(['message' => 'Caixa aberto com sucesso.'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, int $id): CashRegisterResource
    {
        $register = $this->findForUser($request->user(), $id, 'view');

        return new CashRegisterResource(
            $register->load([...CashRegisterResource::RESOURCE_RELATIONS, 'movements.paymentMethod', 'movements.user'])
        );
    }

    public function movements(Request $request, int $id): AnonymousResourceCollection
    {
        $register = $this->findForUser($request->user(), $id, 'view');

        $movements = $register->movements()
            ->with(['paymentMethod', 'user:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        return CashRegisterMovementResource::collection($movements);
    }

    public function storeMovement(StoreCashMovementRequest $request, int $id): JsonResponse
    {
        $register = $this->findForUser($request->user(), $id, 'operate');
        $this->assertInScope($request->user(), PaymentMethod::class, 'payment_method_id', $request->input('payment_method_id'));
        $this->assertInScope($request->user(), FinancialAccount::class, 'account_id', $request->input('account_id'));

        $movement = $this->cashRegisters->recordMovement(
            $register,
            CashMovementType::from($request->string('type')->toString()),
            (float) $request->input('amount'),
            $request->string('description')->toString(),
            $request->user(),
            $request->filled('payment_method_id') ? $request->integer('payment_method_id') : null,
            $request->filled('account_id') ? $request->integer('account_id') : null,
            null,
            $request->filled('occurred_at') ? $request->date('occurred_at') : null,
        );

        return (new CashRegisterMovementResource($movement->load(['paymentMethod', 'user:id,name'])))
            ->additional(['message' => 'Movimento registrado.'])
            ->response()
            ->setStatusCode(201);
    }

    public function close(CloseCashRegisterRequest $request, int $id): CashRegisterResource
    {
        $register = $this->findForUser($request->user(), $id, 'close');

        $closed = $this->cashRegisters->close(
            $register,
            $request->user(),
            $this->normalizeCounted($request->input('counted', [])),
            $request->input('notes'),
        );

        return new CashRegisterResource($closed);
    }

    public function settle(Request $request, int $id): CashRegisterResource
    {
        $register = $this->findForUser($request->user(), $id, 'settle');

        return new CashRegisterResource($this->cashRegisters->settle($register, $request->user()));
    }

    public function review(Request $request, int $id): CashRegisterResource
    {
        $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $register = $this->findForUser($request->user(), $id, 'review');

        return new CashRegisterResource(
            $this->cashRegisters->reopenForReview($register, $request->user(), $request->string('reason')->toString())
        );
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $register = $this->findForUser($request->user(), $id, 'preview');

        return response()->json(['data' => $this->cashRegisters->closingPreview($register)]);
    }

    /**
     * Chaves de `counted` chegam do JSON sempre como string. `"none"` é o balde do movimento
     * manual sem forma de pagamento; o resto vira int para casar com `payment_method_id`.
     *
     * @param  array<string, mixed>  $counted
     * @return array<int|string, float>
     */
    private function normalizeCounted(array $counted): array
    {
        $normalized = [];

        foreach ($counted as $key => $value) {
            $normalized[is_numeric($key) ? (int) $key : $key] = round((float) $value, 2);
        }

        return $normalized;
    }

    /**
     * `exists:` do Form Request só prova que o id existe em ALGUMA clínica; aqui ele tem que
     * ser desta. Sem isto, o movimento cairia na coluna de uma forma de outra clínica.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     */
    private function assertInScope(User $user, string $model, string $field, mixed $id): void
    {
        if ($id === null || $id === '') {
            return;
        }

        if (! $this->scope->scopeQuery($model::query(), $user)->whereKey((int) $id)->exists()) {
            throw ValidationException::withMessages([$field => 'Registro não encontrado nesta clínica.']);
        }
    }

    private function findForUser(User $user, int $id, string $ability): CashRegister
    {
        $register = $this->scopedQuery($user)
            ->with(['movements.paymentMethod', ...CashRegisterResource::RESOURCE_RELATIONS])
            ->findOrFail($id);

        Gate::forUser($user)->authorize($ability, $register);

        return $register;
    }

    /**
     * @return Builder<CashRegister>
     */
    private function scopedQuery(User $user): Builder
    {
        return $this->scope->scopeQuery(CashRegister::query(), $user);
    }
}
