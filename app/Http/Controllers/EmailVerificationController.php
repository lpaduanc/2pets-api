<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\EmailVerificationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly EmailVerificationService $emailVerificationService) {}

    public function verify(Request $request, string $token)
    {
        $user = User::where('email_verification_token', $token)->first();

        if (! $user) {
            return response()->json(['message' => 'Token de verificação inválido.'], 404);
        }

        if ($this->emailVerificationService->isTokenExpired($user)) {
            return response()->json([
                'message' => 'Este link de verificação expirou. Solicite um novo e-mail de verificação.',
                'expired' => true,
            ], 410);
        }

        $user->update([
            // `email_verified` (accessor) deriva de `email_verified_at` — nunca gravado direto.
            'email_verified_at' => now(),
            'email_verification_token' => null,
        ]);

        return response()->json([
            'message' => 'E-mail verificado com sucesso!',
            'user' => new UserResource($user),
        ]);
    }

    public function resend(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return response()->json(['message' => 'Usuário não encontrado.'], 404);
        }

        if ($user->email_verified) {
            return response()->json(['message' => 'Este e-mail já foi verificado.'], 400);
        }

        try {
            $this->emailVerificationService->sendVerificationEmail($user);

            return response()->json(['message' => 'E-mail de verificação enviado.']);
        } catch (TooManyRequestsHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }
    }
}
