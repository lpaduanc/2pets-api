<?php

namespace App\Services\Commercial\Package;

use App\Enums\SoldPackageStatus;
use App\Models\SoldPackage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prorrogação da validade de pacote vendido (PLANO 2.7), com log de/para e motivo. Recusa:
 *  - pacote cancelado ou sem validade (ilimitado);
 *  - pacote cujo saldo vencido JÁ foi resolvido (virou crédito ou receita) — prorrogar ali
 *    devolveria sessões que já saíram para outro lugar; se for o caso, use o crédito do cliente;
 *  - data nova que não estenda (≤ validade atual) ou que já esteja no passado.
 */
final class SoldPackageValidityService
{
    public function extend(SoldPackage $package, string $newDate, string $reason, User $user): SoldPackage
    {
        $to = CarbonImmutable::parse($newDate)->startOfDay();

        abort_if($package->status === SoldPackageStatus::CANCELLED, 422, 'Pacote cancelado não tem validade para prorrogar.');
        abort_if($package->expires_at === null, 422, 'Este pacote não tem validade (é ilimitado).');
        abort_if($package->expiration_resolved_at !== null, 422, 'O saldo deste pacote vencido já foi resolvido (crédito ao cliente ou receita); não dá mais para prorrogar.');

        if (! $to->gt($package->expires_at) || $to->lt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['expires_at' => 'Informe uma data posterior à validade atual e a partir de hoje.']);
        }

        return DB::transaction(function () use ($package, $to, $reason, $user): SoldPackage {
            $from = $package->expires_at->toDateString();
            $package->validityExtensions()->create([
                'from_date' => $from,
                'to_date' => $to->toDateString(),
                'reason' => $reason,
                'user_id' => $user->id,
            ]);
            $package->update(['expires_at' => $to->toDateString()]);

            activity('sold_packages')->causedBy($user)->performedOn($package)->event('sold_package.validity_extended')
                ->withProperties(['from' => $from, 'to' => $to->toDateString(), 'reason' => $reason])
                ->log('Validade do pacote prorrogada');

            return $package;
        });
    }
}
