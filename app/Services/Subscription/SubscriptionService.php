<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

final class SubscriptionService
{
    public function __construct(
        private readonly BillingService $billingService
    ) {}

    public function subscribe(
        User $user,
        SubscriptionPlan $plan,
        string $billingCycle = 'monthly'
    ): Subscription {
        return DB::transaction(function () use ($user, $plan, $billingCycle) {
            // Cancel existing subscription if any
            $this->cancelExistingSubscription($user);

            $startsAt = now();
            $trialEndsAt = $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null;

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'subscription_plan_id' => $plan->id,
                'status' => $plan->trial_days > 0 ? 'trialing' : 'active',
                'billing_cycle' => $billingCycle,
                'starts_at' => $startsAt,
                'trial_ends_at' => $trialEndsAt,
            ]);

            $this->initializeUsageTracking($subscription);

            // Create billing subscription if not on trial
            if ($plan->trial_days === 0) {
                $this->billingService->createSubscription($subscription);
            }

            return $subscription;
        });
    }

    public function upgrade(Subscription $subscription, SubscriptionPlan $newPlan): Subscription
    {
        return DB::transaction(function () use ($subscription, $newPlan) {
            $subscription->update([
                'subscription_plan_id' => $newPlan->id,
            ]);

            $this->adjustUsageLimits($subscription);

            // Update billing subscription
            $this->billingService->updateSubscription($subscription);

            return $subscription->fresh();
        });
    }

    public function cancel(Subscription $subscription, bool $immediately = false): void
    {
        if ($immediately) {
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => now(),
            ]);
        } else {
            $subscription->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'ends_at' => $this->calculateEndDate($subscription),
            ]);
        }

        $this->billingService->cancelSubscription($subscription);
    }

    private function cancelExistingSubscription(User $user): void
    {
        $existing = Subscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if ($existing) {
            $this->cancel($existing, true);
        }
    }

    private function initializeUsageTracking(Subscription $subscription): void
    {
        $plan = $subscription->plan;
        $periodStart = now()->startOfMonth();
        $periodEnd = now()->endOfMonth();

        foreach ($plan->limits as $feature => $limit) {
            $subscription->usage()->create([
                'feature' => $feature,
                'used' => 0,
                'limit' => $limit,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
        }
    }

    private function adjustUsageLimits(Subscription $subscription): void
    {
        $plan = $subscription->plan;

        foreach ($plan->limits as $feature => $limit) {
            $usage = $subscription->usage()
                ->where('feature', $feature)
                ->where('period_end', '>=', now())
                ->first();

            if ($usage) {
                $usage->update(['limit' => $limit]);
            }
        }
    }

    private function calculateEndDate(Subscription $subscription): Carbon
    {
        if ($subscription->billing_cycle === 'yearly') {
            return now()->addYear();
        }

        return now()->addMonth();
    }
}

