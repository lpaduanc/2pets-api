<?php

namespace App\Http\Controllers\Backoffice;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureBackofficeAccess;
use App\Http\Resources\Backoffice\StaffResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Login próprio do 2pets Admin. Não reaproveita `POST /login` porque aquele aplica regras do
 * cadastro de tutor/profissional (e-mail verificado, perfil completo) que não fazem sentido para a
 * equipe — e porque aqui só entra quem é da equipe: o resto recebe a mesma resposta de senha errada.
 */
class AuthController extends Controller
{
    public const TOKEN_NAME = 'backoffice';

    private const TOKEN_TTL_HOURS = 12;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password)
            || ! EnsureBackofficeAccess::isStaff($user)) {
            return response()->json(['message' => 'E-mail ou senha inválidos.'], 401);
        }

        if ($user->is_suspended) {
            return response()->json(['message' => 'Seu acesso ao admin foi desativado.'], 403);
        }

        $token = $user->createToken(self::TOKEN_NAME, ['*'], now()->addHours(self::TOKEN_TTL_HOURS));

        activity('backoffice')->causedBy($user)->event('staff.login')->log('Login no 2pets Admin');

        return response()->json([
            'access_token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => new StaffResource($user),
        ]);
    }

    public function me(Request $request): StaffResource
    {
        return new StaffResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sessão encerrada.']);
    }
}
