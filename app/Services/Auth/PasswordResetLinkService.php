<?php

namespace App\Services\Auth;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Emite e envia um link de definição/redefinição de senha, usando o mesmo mecanismo de
 * `AuthController::forgotPassword` (token de 64 caracteres, hash em `password_reset_tokens`,
 * expiração de 60 minutos conferida em `AuthController::resetPassword`).
 *
 * Extraído para ser reutilizável por qualquer fluxo que precise dar a uma pessoa o meio de
 * escolher sua própria senha — por exemplo, uma conta criada por um profissional em nome de
 * um cliente, que nunca deve nascer com senha conhecida por terceiros.
 */
final class PasswordResetLinkService
{
    private const TOKEN_LENGTH = 64;

    public function send(User $user): void
    {
        $token = Str::random(self::TOKEN_LENGTH);

        $this->storeToken($user->email, $token);
        $this->sendMail($user, $token);
    }

    private function storeToken(string $email, string $token): void
    {
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($token), 'created_at' => now()]
        );
    }

    private function sendMail(User $user, string $token): void
    {
        try {
            $appUrl = rtrim(config('app.frontend_url') ?? config('app.url'), '/');
            $resetUrl = $appUrl.'/reset-password?email='.urlencode($user->email).'&token='.urlencode($token);

            Mail::to($user->email)->send(new ResetPasswordMail($user->name, $resetUrl));
        } catch (Throwable $failure) {
            Log::error('Failed to send password set-up email', [
                'user_id' => $user->id,
                'error' => $failure->getMessage(),
            ]);
        }
    }
}
