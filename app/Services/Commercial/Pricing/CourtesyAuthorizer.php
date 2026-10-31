<?php

namespace App\Services\Commercial\Pricing;

use App\Models\Sale;
use App\Models\User;
use App\Services\Commercial\Authorization\SalePermissionGate;

/**
 * Quem pode marcar uma linha como brinde, cortesia ou retorno (`sales.grant_courtesy`, PLANO
 * 1.4) NESTA venda — pelo cargo na organização da venda (`SalePermissionGate`).
 */
final class CourtesyAuthorizer
{
    public const PERMISSION = 'sales.grant_courtesy';

    public function __construct(private readonly SalePermissionGate $gate) {}

    public function allows(User $user, Sale $sale): bool
    {
        return $this->gate->allows($user, $sale, self::PERMISSION);
    }
}
