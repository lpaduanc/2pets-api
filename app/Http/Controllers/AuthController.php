<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use App\Services\Organization\UserRoleReconciler;
use App\Services\Registration\RegistrationContinuationTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function __construct(
        private readonly UserRoleReconciler $roleReconciler,
        private readonly RegistrationContinuationTokenService $continuationTokenService,
    ) {}

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
            // Sem `email_verified_at`: `User::$emailVerified` (accessor) deriva `false` até a
            // verificação de e-mail gravar o timestamp — ver `EmailVerificationController::verify()`.
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

        // Autorização é decidida pelo papel Spatie; a taxonomia canônica de tipo de negócio
        // dita qual papel. Pessoa recém-cadastrada não tem vínculo organizacional, então o
        // reconciliador só aplica a Fonte A (`user_type`) — mesmo resultado do `assignRole`
        // avulso que existia aqui, mas já pronto para revogar se o vínculo for perdido depois.
        try {
            $this->roleReconciler->reconcile($user);
        } catch (\Exception $e) {
            // Role might not exist yet if seeder hasn't run — graceful fallback
            \Illuminate\Support\Facades\Log::warning('Could not assign spatie role: '.$e->getMessage());
        }

        // Send verification email (except for pending company registrations)
        if ($role !== 'company' || $user->registration_status === 'approved') {
            try {
                app(\App\Services\EmailVerificationService::class)->sendVerificationEmail($user);
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error('Failed to send verification email: '.$e->getMessage());
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

    /**
     * `Auth::guard('web')` explícito, não o guard PADRÃO sem nome: achado escrevendo o
     * teste de ponta a ponta da jornada de agendamento — dentro do MESMO processo/
     * container (é o que o harness de teste HTTP do Laravel faz ao simular vários
     * requests em sequência, exatamente como um app real percorre login → ação →
     * segundo login), qualquer chamada anterior a uma rota `auth:sanctum` já bem-sucedida
     * troca o guard PADRÃO para `RequestGuard` (via `Auth::shouldUse()`, que o middleware
     * `Authenticate` chama), e `RequestGuard` não implementa `attempt()` —
     * `BadMethodCallException` em produção só não acontece porque o php-fpm deste projeto
     * usa um processo novo por request (Octane teria o mesmo problema). Fixar o guard é
     * a correção correta de qualquer forma: login nunca deveria depender de qual guard
     * "está por cima" no momento.
     */
    public function login(LoginRequest $request)
    {
        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login details',
            ], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();

        // Desativação voluntária (AccountDeactivationService): a mensagem convida a
        // reativar em vez de recusar seco. Suspensão (punitiva) tem prioridade quando as
        // duas coexistem — quem foi suspenso não pode se autorreativar por aqui, então a
        // resposta já aponta para o suporte em vez de para o endpoint de reativação.
        if ($user->isDeactivated()) {
            if ($user->is_suspended) {
                return response()->json([
                    'message' => 'Sua conta foi suspensa. Entre em contato com o suporte para mais informações.',
                    'account_suspended' => true,
                ], 403);
            }

            return response()->json([
                'message' => 'Sua conta está desativada. Você pode reativá-la a qualquer momento.',
                'account_deactivated' => true,
                'can_reactivate' => true,
            ], 403);
        }

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
        if (! $user->email_verified) {
            $canResend = app(\App\Services\EmailVerificationService::class)->canSendEmail($user->email);

            return response()->json([
                'message' => 'Please verify your email before logging in.',
                'email_not_verified' => true,
                'can_resend' => $canResend,
                'user' => new UserResource($user),
            ], 403);
        }

        // Check profile completion
        if (! $user->profile_completed && $user->role !== 'tutor') {
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
            'message' => 'Logged out successfully',
        ]);
    }

    public function user(Request $request)
    {
        $user = $request->user()->load(['roles', 'professional', 'media', 'activeOrganizationMemberships.organization']);

        return new UserResource($user);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
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
            $appUrl = rtrim(config('app.frontend_url') ?? config('app.url'), '/');
            $resetUrl = $appUrl.'/reset-password?email='.urlencode($user->email).'&token='.urlencode($token);

            Mail::to($user->email)->send(new ResetPasswordMail($user->name, $resetUrl));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send password reset email: '.$e->getMessage());
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

        if (! $record || ! Hash::check($request->token, $record->token)) {
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

        if (! $user) {
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

            // Trava de segurança (docs/atendimento-veterinario/07-contrato-agendamento-pet-novo.md
            // §2): conta com `password IS NULL` (não reivindicada — nasceu do fluxo de paciente
            // novo) não pode autenticar por NENHUM caminho, nem provando posse do e-mail via
            // Google. `updateOrCreate()` abaixo daria login imediato a essa conta sem nunca
            // mostrar nome/CPF/pet — a mesma tela de continuação que o link de e-mail mostra.
            // Em vez de logar, emite o mesmo token de continuação e devolve para o frontend
            // redirecionar para lá.
            $unclaimed = User::where('email', $googleUser->getEmail())->whereNull('password')->first();

            if ($unclaimed !== null) {
                return response()->json([
                    'message' => 'Encontramos um cadastro iniciado para este e-mail. Continue o cadastro para acessar sua conta.',
                    'requires_registration_continuation' => true,
                    'continuation_token' => $this->continuationTokenService->issue($unclaimed),
                ], 409);
            }

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
            return response()->json(['error' => 'Authentication failed: '.$e->getMessage()], 401);
        }
    }
}
