<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Services\Registration\RegistrationContinuationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Continuação de cadastro de uma conta não reivindicada — contrato
 * `docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md` §6. A tela já sabe quem é
 * o tutor: nunca cria conta nova, nunca mostra "CPF já cadastrado".
 */
class RegistrationContinuationController extends Controller
{
    private const INVALID_TOKEN_MESSAGE = 'Este link não é mais válido. Peça um novo cadastro.';

    public function __construct(
        private readonly RegistrationContinuationTokenService $continuationTokenService,
    ) {}

    /** GET /register/continue/{token} — pré-visualização, NÃO consome o token. */
    public function show(Request $request, string $token): JsonResponse
    {
        $user = $this->continuationTokenService->peek($token);

        if ($user === null) {
            return response()->json(['message' => self::INVALID_TOKEN_MESSAGE], 404);
        }

        return response()->json([
            'data' => [
                'name' => $user->name,
                'cpf' => $user->cpf,
                'email' => $user->email,
                'pets' => $user->pets()->get(['id', 'name', 'species']),
            ],
        ]);
    }

    /** POST /register/continue/{token} — define a senha e consome o token de uso único. */
    public function complete(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = $this->continuationTokenService->consume($token);

        if ($user === null) {
            return response()->json(['message' => self::INVALID_TOKEN_MESSAGE], 404);
        }

        $user->update([
            'password' => $request->string('password')->value(),
            'registration_status' => 'approved',
            // Clicar o link já prova posse do e-mail — mesmo padrão implícito da verificação
            // por token. Só grava se ainda não tinha: nunca sobrescreve uma verificação anterior.
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $accessToken = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'user' => new UserResource($user->fresh()),
            'profile_completed' => $user->profile_completed,
        ]);
    }
}
