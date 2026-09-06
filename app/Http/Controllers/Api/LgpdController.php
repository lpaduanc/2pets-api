<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsentLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class LgpdController extends Controller
{
    /**
     * Consents the user can toggle. Keys map 1:1 to columns on `users`.
     * Contractual consents (terms, privacy) are required for service and not listed here.
     */
    private const GRANULAR_KEYS = [
        'marketing_consent',
        'data_sharing_consent',
        'consent_search_visibility',
        'consent_share_with_vets',
        'consent_push_notifications',
        'consent_sms_transactional',
        'consent_whatsapp_transactional',
        'consent_analytics',
    ];

    public function exportData(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = [
            'personal_data' => [
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'cpf' => $user->cpf,
                'birth_date' => $user->birth_date,
                'address' => $user->address,
                'city' => $user->city,
                'state' => $user->state,
                'zip_code' => $user->zip_code,
                'created_at' => $user->created_at,
            ],
            'consents' => $this->consentPayload($user),
            'consent_history' => ConsentLog::where('user_id', $user->id)
                ->orderBy('occurred_at')
                ->get()
                ->toArray(),
            'pets' => $user->pets()->with(['vaccinations', 'medications', 'dewormings'])->get()->toArray(),
            'appointments' => $user->appointmentsAsClient()->get()->toArray(),
            'reviews' => $user->reviews()->get()->toArray(),
            'favorites' => $user->favorites()->get()->toArray(),
            'notifications' => $user->notifications()->limit(100)->get()->toArray(),
        ];

        if ($user->professional) {
            $data['professional_profile'] = $user->professional->toArray();
            $data['services'] = $user->professional->services()->get()->toArray();
        }

        Log::info('LGPD: Data export requested', ['user_id' => $user->id]);

        return response()->json([
            'message' => 'Dados exportados com sucesso',
            'data' => $data,
            'exported_at' => now()->toIso8601String(),
        ]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|in:DELETAR,DELETE',
        ]);

        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Senha incorreta'], 403);
        }

        DB::beginTransaction();
        try {
            $user->pets()->each(function ($pet) {
                $pet->vaccinations()->delete();
                $pet->medications()->delete();
                $pet->dewormings()->delete();
                $pet->delete();
            });

            $user->favorites()->delete();
            DB::table('notifications')->where('notifiable_id', $user->id)->delete();

            $user->tokens()->delete();

            $user->update([
                'name' => 'Usuario Removido',
                'email' => "deleted_{$user->id}@removed.2pets.com.br",
                'phone' => null,
                'cpf' => null,
                'birth_date' => null,
                'address' => null,
                'city' => null,
                'state' => null,
                'zip_code' => null,
                'is_suspended' => true,
                'deleted_at' => now(),
            ]);

            $this->logConsent($user->id, 'account', false, $request, 'deletion');

            DB::commit();

            Log::info('LGPD: Account deleted', ['user_id' => $user->id]);

            return response()->json(['message' => 'Conta excluida com sucesso. Seus dados foram anonimizados.']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('LGPD: Failed to delete account', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Erro ao excluir conta. Tente novamente.'], 500);
        }
    }

    public function consentStatus(Request $request): JsonResponse
    {
        return response()->json(['consents' => $this->consentPayload($request->user())]);
    }

    public function updateConsent(Request $request): JsonResponse
    {
        $rules = array_fill_keys(
            array_map(fn ($k) => "$k", self::GRANULAR_KEYS),
            'sometimes|boolean'
        );
        $validated = $request->validate($rules);

        $user = $request->user();
        $originals = $user->only(array_keys($validated));

        $user->update($validated);

        foreach ($validated as $key => $granted) {
            if (($originals[$key] ?? null) === (bool) $granted) {
                continue;
            }
            $this->logConsent($user->id, $key, (bool) $granted, $request);
        }

        Log::info('LGPD: Consent updated', ['user_id' => $user->id, 'changes' => $validated]);

        return response()->json([
            'message' => 'Preferencias atualizadas com sucesso',
            'consents' => $this->consentPayload($user->fresh()),
        ]);
    }

    private function consentPayload($user): array
    {
        return [
            // Contractual — must be accepted to use the service.
            'terms_accepted' => (bool) $user->terms_accepted_at,
            'terms_accepted_at' => $user->terms_accepted_at,
            'privacy_accepted' => (bool) $user->privacy_accepted_at,
            'privacy_accepted_at' => $user->privacy_accepted_at,

            // Granular — user can toggle any of these independently.
            'marketing_consent' => (bool) $user->marketing_consent,
            'data_sharing_consent' => (bool) $user->data_sharing_consent,
            'consent_search_visibility' => (bool) $user->consent_search_visibility,
            'consent_share_with_vets' => (bool) $user->consent_share_with_vets,
            'consent_push_notifications' => (bool) $user->consent_push_notifications,
            'consent_sms_transactional' => (bool) $user->consent_sms_transactional,
            'consent_whatsapp_transactional' => (bool) $user->consent_whatsapp_transactional,
            'consent_analytics' => (bool) $user->consent_analytics,
        ];
    }

    private function logConsent(int $userId, string $key, bool $granted, Request $request, string $source = 'self'): void
    {
        ConsentLog::create([
            'user_id' => $userId,
            'consent_key' => $key,
            'granted' => $granted,
            'source' => $source,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'occurred_at' => now(),
        ]);
    }
}
