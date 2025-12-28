<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\User;

final class FeatureGateService
{
    public function canAccessFeature(User $user, string $feature): bool
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return false;
        }

        return $subscription->plan->hasFeature($feature);
    }

    public function checkUsageLimit(User $user, string $feature): bool
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return false;
        }

        $usage = $subscription->usage()
            ->where('feature', $feature)
            ->where('period_end', '>=', now())
            ->first();

        if (!$usage) {
            return false;
        }

        return $usage->isWithinLimit();
    }

    public function incrementUsage(User $user, string $feature, int $amount = 1): void
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return;
        }

        $usage = $subscription->usage()
            ->where('feature', $feature)
            ->where('period_end', '>=', now())
            ->first();

        if ($usage) {
            $usage->incrementUsage($amount);
        }
    }

    public function getRemainingUsage(User $user, string $feature): int
    {
        $subscription = $this->getActiveSubscription($user);

        if (!$subscription) {
            return 0;
        }

        $usage = $subscription->usage()
            ->where('feature', $feature)
            ->where('period_end', '>=', now())
            ->first();

        return $usage ? $usage->getRemainingUsage() : 0;
    }

    private function getActiveSubscription(User $user): ?Subscription
    {
        return Subscription::where('user_id', $user->id)
            ->where(function ($query) {
                $query->where('status', 'active')
                      ->orWhere('status', 'trialing');
            })
            ->first();
    }
}

