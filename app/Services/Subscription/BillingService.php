<?php

namespace App\Services\Subscription;

use App\Models\Subscription;
use App\Services\Payment\StripeService;
use Illuminate\Support\Facades\Log;

final class BillingService
{
    private StripeService $stripe;

    public function __construct(StripeService $stripe)
    {
        $this->stripe = $stripe;
    }

    /**
     * Create a subscription in the billing gateway and activate it.
     */
    public function createSubscription(Subscription $subscription): void
    {
        $user = $subscription->user;
        $plan = $subscription->plan;

        if (!$plan || !$plan->stripe_price_id) {
            // No Stripe price configured — activate locally (free plan or dev mode)
            $subscription->update(['status' => 'active']);
            Log::info('BillingService: Subscription activated locally (no Stripe price)', [
                'subscription_id' => $subscription->id,
            ]);
            return;
        }

        try {
            $result = $this->stripe->createSubscription($user, $plan->stripe_price_id);

            $subscription->update([
                'gateway_subscription_id' => $result['id'],
                'status' => $result['status'] === 'trialing' ? 'trialing' : 'active',
                'trial_ends_at' => $result['status'] === 'trialing'
                    ? now()->addDays(7)
                    : null,
            ]);

            Log::info('BillingService: Subscription created in Stripe', [
                'subscription_id' => $subscription->id,
                'stripe_id' => $result['id'],
                'status' => $result['status'],
            ]);
        } catch (\Exception $e) {
            Log::error('BillingService: Failed to create subscription in gateway', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            // Activate locally as fallback (dev/test mode)
            $subscription->update(['status' => 'active']);
        }
    }

    /**
     * Update a subscription in the billing gateway (e.g., plan change).
     */
    public function updateSubscription(Subscription $subscription): void
    {
        if (!$subscription->gateway_subscription_id) {
            Log::info('BillingService: No gateway ID, skipping update', [
                'subscription_id' => $subscription->id,
            ]);
            return;
        }

        // Plan upgrades/downgrades would be handled here via Stripe API
        // For now, log the intent — full implementation requires Stripe Subscription Items API
        Log::info('BillingService: Subscription update requested', [
            'subscription_id' => $subscription->id,
            'gateway_id' => $subscription->gateway_subscription_id,
        ]);
    }

    /**
     * Cancel a subscription in the billing gateway.
     */
    public function cancelSubscription(Subscription $subscription): void
    {
        if (!$subscription->gateway_subscription_id) {
            $subscription->cancel();
            Log::info('BillingService: Subscription cancelled locally (no gateway ID)', [
                'subscription_id' => $subscription->id,
            ]);
            return;
        }

        try {
            $cancelled = $this->stripe->cancelSubscription($subscription->gateway_subscription_id);

            if ($cancelled) {
                $subscription->cancel();
                Log::info('BillingService: Subscription cancelled in Stripe', [
                    'subscription_id' => $subscription->id,
                    'stripe_id' => $subscription->gateway_subscription_id,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('BillingService: Failed to cancel subscription in gateway', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);

            // Cancel locally anyway to not trap the user
            $subscription->cancel();
        }
    }

    /**
     * Process a webhook event from the billing gateway.
     */
    public function processWebhook(array $payload): void
    {
        $type = $payload['type'] ?? null;
        $data = $payload['data']['object'] ?? [];

        Log::info('BillingService: Processing webhook', ['type' => $type]);

        match ($type) {
            'invoice.payment_succeeded' => $this->handlePaymentSucceeded($data),
            'invoice.payment_failed' => $this->handlePaymentFailed($data),
            'customer.subscription.deleted' => $this->handleSubscriptionCancelled($data),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($data),
            default => Log::info('BillingService: Unhandled webhook type', ['type' => $type]),
        };
    }

    private function handlePaymentSucceeded(array $data): void
    {
        $gatewayId = $data['subscription'] ?? null;
        if (!$gatewayId) {
            return;
        }

        $subscription = Subscription::where('gateway_subscription_id', $gatewayId)->first();
        if ($subscription && $subscription->status !== 'active') {
            $subscription->update([
                'status' => 'active',
                'ends_at' => isset($data['period_end'])
                    ? \Carbon\Carbon::createFromTimestamp($data['period_end'])
                    : $subscription->ends_at,
            ]);
        }
    }

    private function handlePaymentFailed(array $data): void
    {
        $gatewayId = $data['subscription'] ?? null;
        if (!$gatewayId) {
            return;
        }

        $subscription = Subscription::where('gateway_subscription_id', $gatewayId)->first();
        if ($subscription) {
            $subscription->update(['status' => 'past_due']);
            Log::warning('BillingService: Payment failed for subscription', [
                'subscription_id' => $subscription->id,
            ]);
        }
    }

    private function handleSubscriptionCancelled(array $data): void
    {
        $gatewayId = $data['id'] ?? null;
        if (!$gatewayId) {
            return;
        }

        $subscription = Subscription::where('gateway_subscription_id', $gatewayId)->first();
        if ($subscription) {
            $subscription->cancel();
        }
    }

    private function handleSubscriptionUpdated(array $data): void
    {
        $gatewayId = $data['id'] ?? null;
        if (!$gatewayId) {
            return;
        }

        $subscription = Subscription::where('gateway_subscription_id', $gatewayId)->first();
        if ($subscription) {
            $status = $data['status'] ?? $subscription->status;
            $subscription->update([
                'status' => $status,
                'ends_at' => isset($data['current_period_end'])
                    ? \Carbon\Carbon::createFromTimestamp($data['current_period_end'])
                    : $subscription->ends_at,
            ]);
        }
    }
}
