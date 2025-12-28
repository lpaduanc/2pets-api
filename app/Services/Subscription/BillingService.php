<?php

namespace App\Services\Subscription;

use App\Models\Subscription;

final class BillingService
{
    private ?string $gatewayKey;

    public function __construct()
    {
        // Using Asaas for Brazilian market or Stripe for international
        $this->gatewayKey = config('services.billing.gateway_key');
    }

    public function createSubscription(Subscription $subscription): void
    {
        if (!$this->gatewayKey) {
            return; // Billing not configured
        }

        // TODO: Implement actual billing gateway integration
        // For now, mark as active
        $subscription->update(['status' => 'active']);
    }

    public function updateSubscription(Subscription $subscription): void
    {
        if (!$this->gatewayKey || !$subscription->gateway_subscription_id) {
            return;
        }

        // TODO: Update subscription in billing gateway
    }

    public function cancelSubscription(Subscription $subscription): void
    {
        if (!$this->gatewayKey || !$subscription->gateway_subscription_id) {
            return;
        }

        // TODO: Cancel subscription in billing gateway
    }

    public function processWebhook(array $payload): void
    {
        // TODO: Handle billing gateway webhooks
        // Update subscription status based on payment events
    }
}

