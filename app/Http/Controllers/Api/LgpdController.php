<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LgpdController extends Controller
{
    /**
     * Export all user data (LGPD Art. 18, V - Portability)
     */
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
            'pets' => $user->pets()->with(['vaccinations', 'medications', 'dewormings'])->get()->toArray(),
            'appointments' => $user->appointmentsAsClient()->get()->toArray(),
            'reviews' => $user->reviews()->get()->toArray(),
            'favorites' => $user->favorites()->get()->toArray(),
            'notifications' => $user->notifications()->limit(100)->get()->toArray(),
        ];

        // If professional, include professional data
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

    /**
     * Delete user account and all associated data (LGPD Art. 18, VI - Deletion)
     */
    public function deleteAccount(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string',
            'confirmation' => 'required|in:DELETAR,DELETE',
        ]);

        $user = $request->user();

        if (!password_verify($request->password, $user->password)) {
            return response()->json(['message' => 'Senha incorreta'], 403);
        }

        DB::beginTransaction();
        try {
            // Soft delete pets
            $user->pets()->each(function ($pet) {
                $pet->vaccinations()->delete();
                $pet->medications()->delete();
                $pet->dewormings()->delete();
                $pet->delete();
            });

            // Delete favorites, notifications
            $user->favorites()->delete();
            DB::table('notifications')->where('notifiable_id', $user->id)->delete();

            // Revoke all tokens
            $user->tokens()->delete();

            // Anonymize user data but keep for audit purposes
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

            DB::commit();

            Log::info('LGPD: Account deleted', ['user_id' => $user->id]);

            return response()->json(['message' => 'Conta excluida com sucesso. Seus dados foram anonimizados.']);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('LGPD: Failed to delete account', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'Erro ao excluir conta. Tente novamente.'], 500);
        }
    }

    /**
     * View consent status (LGPD Art. 8)
     */
    public function consentStatus(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'consents' => [
                'terms_accepted' => (bool) $user->terms_accepted_at,
                'terms_accepted_at' => $user->terms_accepted_at,
                'privacy_accepted' => (bool) $user->privacy_accepted_at,
                'privacy_accepted_at' => $user->privacy_accepted_at,
                'marketing_consent' => (bool) $user->marketing_consent,
                'data_sharing_consent' => (bool) $user->data_sharing_consent,
            ],
        ]);
    }

    /**
     * Update consent preferences
     */
    public function updateConsent(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'marketing_consent' => 'sometimes|boolean',
            'data_sharing_consent' => 'sometimes|boolean',
        ]);

        $request->user()->update($validated);

        Log::info('LGPD: Consent updated', ['user_id' => $request->user()->id, 'changes' => $validated]);

        return response()->json(['message' => 'Preferencias atualizadas com sucesso']);
    }
}
