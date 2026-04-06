<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function register(RegisterRequest $request)
    {
        $validatedData = $request->validated();

        // Set role based on user_type
        if ($validatedData['user_type'] === 'tutor') {
            $role = 'tutor';
        } elseif ($validatedData['user_type'] === 'company') {
            $role = 'company';
        } else {
            $role = 'professional';
        }

        // Prepare user data
        $userData = [
            'name' => $validatedData['name'],
            'email' => $validatedData['email'],
            'phone' => $validatedData['phone'],
            'user_type' => $validatedData['user_type'],
            'role' => $role,
            'password' => bcrypt($validatedData['password']),
            'email_verified' => false,
            'profile_completed' => false,
        ];

        // Add company-specific fields if provided
        if ($validatedData['user_type'] === 'company' && isset($validatedData['additional_data'])) {
            $additionalData = $validatedData['additional_data'];
            $userData['cnpj'] = $additionalData['cnpj'] ?? null;
            $userData['employee_count'] = $additionalData['employee_count'] ?? null;
            $userData['additional_notes'] = $additionalData['message'] ?? null;
            $userData['registration_status'] = 'pending'; // Company registrations start as pending
        } else {
            $userData['registration_status'] = 'approved'; // Others are auto-approved
        }

        $user = User::create($userData);

        // Assign spatie role based on user_type
        $spatieRole = match ($validatedData['user_type']) {
            'tutor' => 'tutor',
            'vet' => 'vet_freelancer',
            'clinic' => 'clinic_owner',
            'petshop' => 'petshop_owner',
            default => null,
        };

        if ($spatieRole) {
            try {
                $user->assignRole($spatieRole);
            } catch (\Exception $e) {
                // Role might not exist yet if seeder hasn't run — graceful fallback
                \Illuminate\Support\Facades\Log::warning('Could not assign spatie role: ' . $e->getMessage());
            }
        }

        // Send verification email (except for pending company registrations)
        if ($role !== 'company' || $user->registration_status === 'approved') {
            try {
                app(\App\Services\EmailVerificationService::class)->sendVerificationEmail($user);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send verification email: ' . $e->getMessage());
            }
        }

        // Different message for company registrations
        $message = $role === 'company' && $userData['registration_status'] === 'pending'
            ? 'Thank you for your interest! Our team will review your request and contact you within 24 hours.'
            : 'Registration successful. Please check your email to verify your account.';

        return response()->json([
            'message' => $message,
            'user' => new UserResource($user),
            'redirect_to_app' => $role !== 'company', // Don't redirect companies to app yet
            'pending_approval' => $role === 'company' && $userData['registration_status'] === 'pending',
        ], 201);
    }

    public function login(LoginRequest $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login details'
            ], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();

        // Check registration status for companies
        if ($user->role === 'company' && $user->registration_status === 'pending') {
            return response()->json([
                'message' => 'Your registration is still under review. Our team will contact you soon.',
                'pending_approval' => true,
            ], 403);
        }

        if ($user->role === 'company' && $user->registration_status === 'rejected') {
            return response()->json([
                'message' => 'Your registration was not approved. Please contact support for more information.',
                'registration_rejected' => true,
            ], 403);
        }

        // Check email verification
        if (!$user->email_verified) {
            $canResend = app(\App\Services\EmailVerificationService::class)->canSendEmail($user->email);

            return response()->json([
                'message' => 'Please verify your email before logging in.',
                'email_not_verified' => true,
                'can_resend' => $canResend,
                'user' => new UserResource($user),
            ], 403);
        }

        // Check profile completion
        if (!$user->profile_completed && $user->role !== 'tutor') {
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
                'profile_completed' => false,
                'requires_profile_completion' => true,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'profile_completed' => $user->profile_completed,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user()->load(['roles', 'professional', 'media']);
        return new UserResource($user);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            // Return success even if email doesn't exist (security: no email enumeration)
            return response()->json([
                'message' => 'Se o e-mail estiver cadastrado, enviaremos um link de recuperação.',
            ]);
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $request->email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        try {
            Mail::raw(
                "Olá {$user->name},\n\nVocê solicitou a recuperação de senha da sua conta 2Pets.\n\nUse o código abaixo para redefinir sua senha:\n\n{$token}\n\nEste código expira em 60 minutos.\n\nSe você não solicitou esta recuperação, ignore este e-mail.\n\nEquipe 2Pets",
                function ($message) use ($user) {
                    $message->to($user->email)
                        ->subject('2Pets - Recuperação de Senha');
                }
            );
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send password reset email: ' . $e->getMessage());
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, enviaremos um link de recuperação.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $record = DB::table('password_reset_tokens')
            ->where('email', $request->email)
            ->first();

        if (!$record || !Hash::check($request->token, $record->token)) {
            return response()->json([
                'message' => 'Token inválido ou expirado.',
            ], 422);
        }

        // Check if token is expired (60 minutes)
        if (now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $request->email)->delete();
            return response()->json([
                'message' => 'Token expirado. Solicite um novo link de recuperação.',
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Usuário não encontrado.',
            ], 404);
        }

        $user->update(['password' => Hash::make($request->password)]);

        DB::table('password_reset_tokens')->where('email', $request->email)->delete();

        return response()->json([
            'message' => 'Senha redefinida com sucesso. Faça login com sua nova senha.',
        ]);
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            // In a real SPA scenario, the frontend sends the token, and we use userFromToken
            // But Socialite standard flow is redirect.
            // For SPA (Vue), we usually send the 'credential' (ID token) or 'code'.

            // If using vue3-google-login 'code' flow:
            $googleUser = Socialite::driver('google')->stateless()->userFromToken($request->token);

            $user = User::updateOrCreate([
                'email' => $googleUser->getEmail(),
            ], [
                'name' => $googleUser->getName(),
                'google_id' => $googleUser->getId(),
                'password' => bcrypt(Str::random(16)), // Random password for social login
                'email_verified_at' => now(),
            ]);

            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => new UserResource($user),
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Authentication failed: ' . $e->getMessage()], 401);
        }
    }
}
