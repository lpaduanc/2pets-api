<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string|max:20',
            'user_type' => 'required|in:tutor,vet,clinic,laboratory,petshop,pet_hotel,grooming,training,company',
            'password' => 'required|string|min:8|confirmed',
            'additional_data' => 'array|nullable',
        ]);

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
            'user' => $user,
            'redirect_to_app' => $role !== 'company', // Don't redirect companies to app yet
            'pending_approval' => $role === 'company' && $userData['registration_status'] === 'pending',
        ], 201);
    }

    public function login(Request $request)
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login details'
            ], 401);
        }

        $user = User::where('email', $request['email'])->firstOrFail();

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
                'user' => $user,
            ], 403);
        }

        // Check profile completion
        if (!$user->profile_completed && $user->role !== 'tutor') {
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'profile_completed' => false,
                'requires_profile_completion' => true,
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
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
        return response()->json($request->user());
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            // In a real SPA scenario, the frontend sends the token, and we use userFromToken
            // But Socialite standard flow is redirect.
            // For SPA (Vue), we usually send the 'code' or 'token' to the backend.
            // Let's assume the frontend sends the 'credential' (ID token) or 'code'.

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
                'user' => $user,
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Authentication failed: ' . $e->getMessage()], 401);
        }
    }
}
