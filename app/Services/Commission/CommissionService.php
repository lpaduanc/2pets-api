<?php

namespace App\Services\Commission;

use App\Models\Commission;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CommissionService
{
    // Commission rates by transaction type
    private const RATE_APPOINTMENT = 5.00; // 5%
    private const RATE_PRODUCT_SALE = 10.00; // 10%
    private const RATE_SERVICE = 7.00; // 7%
    
    // Minimum payout amount
    private const MIN_PAYOUT = 100.00; // R$ 100

    public function calculateCommission(
        User $professional,
        string $transactionType,
        int $transactionId,
        float $amount
    ): Commission {
        $rate = $this->getCommissionRate($professional, $transactionType);
        $commissionAmount = ($amount * $rate) / 100;

        return Commission::create([
            'professional_id' => $professional->id,
            'transaction_type' => $transactionType,
            'transaction_id' => $transactionId,
            'transaction_amount' => $amount,
            'commission_rate' => $rate,
            'commission_amount' => $commissionAmount,
            'status' => 'pending',
        ]);
    }

    public function getPendingCommissions(User $professional): Collection
    {
        return Commission::where('professional_id', $professional->id)
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->get();
    }

    public function getPendingBalance(User $professional): float
    {
        return (float) Commission::where('professional_id', $professional->id)
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->sum('commission_amount');
    }

    public function canRequestPayout(User $professional): bool
    {
        return $this->getPendingBalance($professional) >= self::MIN_PAYOUT;
    }

    public function createPayout(
        User $professional,
        string $paymentMethod,
        array $paymentDetails
    ): Payout {
        return DB::transaction(function () use ($professional, $paymentMethod, $paymentDetails) {
            $commissions = $this->getPendingCommissions($professional);

            if ($commissions->isEmpty()) {
                throw new \Exception('No commissions available for payout');
            }

            $totalAmount = $commissions->sum('commission_amount');

            if ($totalAmount < self::MIN_PAYOUT) {
                throw new \Exception('Minimum payout amount not reached');
            }

            $payout = Payout::create([
                'professional_id' => $professional->id,
                'total_amount' => $totalAmount,
                'commission_count' => $commissions->count(),
                'status' => 'pending',
                'payment_method' => $paymentMethod,
                'payment_details' => $paymentDetails,
            ]);

            // Link commissions to payout
            $commissions->each(function ($commission) use ($payout) {
                $commission->update(['payout_id' => $payout->id]);
            });

            return $payout;
        });
    }

    private function getCommissionRate(User $professional, string $transactionType): float
    {
        // Check if professional has premium subscription (lower rates)
        $subscription = $professional->subscriptions()
            ->where('status', 'active')
            ->first();

        $isPremium = $subscription && in_array($subscription->plan->tier, ['pro', 'enterprise']);

        $baseRate = match ($transactionType) {
            'appointment' => self::RATE_APPOINTMENT,
            'product_sale' => self::RATE_PRODUCT_SALE,
            'service' => self::RATE_SERVICE,
            default => 5.00,
        };

        // Premium subscribers get 40% discount on commission
        return $isPremium ? $baseRate * 0.6 : $baseRate;
    }
}

