<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeactivationReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\DeactivateAccountRequest;
use App\Http\Requests\Account\ReactivateAccountRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Account\AccountDeactivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Desativação/reativação voluntária da própria conta. Não confundir com
 * `LgpdController::deleteAccount` (direito ao esquecimento, anonimiza a pedido do titular) —
 * aqui nenhum dado é apagado ou alterado além do estado da conta em si.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountDeactivationService $deactivationService,
    ) {}

    public function deactivate(DeactivateAccountRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Senha incorreta'], 403);
        }

        $this->deactivationService->deactivate(
            $user,
            $request->enum('reason', DeactivationReason::class),
            $request->input('note') ?: null,
            $user,
        );

        return response()->json([
            'message' => 'Sua conta foi desativada. Você pode reativá-la quando quiser.',
        ]);
    }

    /**
     * Público: quem chega aqui já está bloqueado no `/login` normal (sem token). As mesmas
     * credenciais de sempre autenticam e, se a conta estiver autodesativada (e não suspensa),
     * reativam e já devolvem um token novo — mesmo formato de resposta do login, para o
     * frontend reaproveitar o handler de "logado com sucesso".
     */
    /**
     * `Auth::guard('web')` explícito — mesma correção de `AuthController::login()`
     * (achado escrevendo o teste de ponta a ponta da jornada de agendamento): o guard
     * PADRÃO sem nome pode já ter sido trocado para `RequestGuard` por uma autenticação
     * `auth:sanctum` anterior no mesmo processo, e `RequestGuard` não tem `attempt()`.
     */
    public function reactivate(ReactivateAccountRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            return response()->json(['message' => 'Credenciais inválidas'], 401);
        }

        $user = User::where('email', $request->input('email'))->firstOrFail();

        $this->deactivationService->reactivate($user);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Conta reativada com sucesso. Bem-vindo de volta!',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->fresh()),
            'profile_completed' => $user->profile_completed,
        ]);
    }
}
