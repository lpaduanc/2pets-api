<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Appointment\UpdateDepositSettingsRequest;
use App\Models\Organization;
use App\Services\Appointment\EstablishmentDepositSettingsResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * `professional/deposit-settings` — Fase 6 do fluxo de agendamento. Configura o sinal no
 * nível do ESTABELECIMENTO (organização OU profissional autônomo, nunca os dois — ver
 * `EstablishmentDepositSettingsResolver`). Override por serviço mora no CRUD de serviços
 * que já existe (`ServiceController`), não aqui.
 */
class DepositSettingsController extends Controller
{
    public function __construct(private readonly EstablishmentDepositSettingsResolver $resolver) {}

    /** GET professional/deposit-settings */
    public function show(Request $request): JsonResponse
    {
        $establishment = $this->resolver->forView($request->user());

        return response()->json([
            'deposit_enabled' => $establishment?->depositEnabled() ?? false,
            'deposit_percentage' => $establishment?->depositPercentage(),
        ]);
    }

    /** PUT professional/deposit-settings */
    public function update(UpdateDepositSettingsRequest $request): JsonResponse
    {
        $establishment = $this->resolver->forWrite($request->user());

        if ($establishment instanceof Organization) {
            Gate::forUser($request->user())->authorize('manageDepositSettings', $establishment);
        }

        $establishment->update([
            'deposit_enabled' => $request->boolean('deposit_enabled'),
            'deposit_percentage' => $request->input('deposit_percentage'),
        ]);

        return response()->json([
            'deposit_enabled' => $establishment->depositEnabled(),
            'deposit_percentage' => $establishment->depositPercentage(),
        ]);
    }
}
