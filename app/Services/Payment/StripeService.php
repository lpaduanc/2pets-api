<?php

namespace App\Services\Payment;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Servico de integracao com Stripe.
 *
 * Quando STRIPE_SECRET_KEY esta configurado, utiliza o SDK real do Stripe.
 * Quando vazio (ambiente de desenvolvimento sem chaves), opera em modo mock
 * retornando dados simulados para permitir desenvolvimento local.
 *
 * Requisito: composer require stripe/stripe-php
 */
final class StripeService
{
    private string $secretKey;
    private string $webhookSecret;

    public function __construct()
    {
        $this->secretKey = config('services.stripe.secret_key', '');
        $this->webhookSecret = config('services.stripe.webhook_secret', '');

        if ($this->isConfigured()) {
            \Stripe\Stripe::setApiKey($this->secretKey);
        }
    }

    /**
     * Verifica se o Stripe esta configurado com chaves reais.
     */
    private function isConfigured(): bool
    {
        return ! empty($this->secretKey) && ! str_starts_with($this->secretKey, 'sk_test_your_');
    }

    // =========================================================================
    // Payment Intents
    // =========================================================================

    /**
     * Cria um PaymentIntent no Stripe.
     *
     * @param  float  $amount      Valor em reais (ex: 150.00)
     * @param  string $currency    Moeda ISO 4217 (default: brl)
     * @param  array  $metadata    Metadados adicionais
     * @return array{id: string, client_secret: string, status: string, amount: int, currency: string}
     */
    public function createPaymentIntent(float $amount, string $currency = 'brl', array $metadata = []): array
    {
        $amountCents = (int) round($amount * 100);

        if (! $this->isConfigured()) {
            return $this->mockCreatePaymentIntent($amountCents, $currency, $metadata);
        }

        try {
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $amountCents,
                'currency' => $currency,
                'metadata' => $metadata,
                'payment_method_types' => ['card'],
            ]);

            Log::info('StripeService: PaymentIntent criado', [
                'id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
            ]);

            return [
                'id' => $paymentIntent->id,
                'client_secret' => $paymentIntent->client_secret,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('StripeService: Falha ao criar PaymentIntent', [
                'error' => $e->getMessage(),
                'amount' => $amountCents,
                'currency' => $currency,
            ]);

            return [
                'id' => null,
                'client_secret' => null,
                'status' => 'error',
                'amount' => $amountCents,
                'currency' => $currency,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Confirma um PaymentIntent existente.
     *
     * @return array{id: string, status: string, amount: int, currency: string}
     */
    public function confirmPayment(string $paymentIntentId): array
    {
        if (! $this->isConfigured()) {
            return $this->mockConfirmPayment($paymentIntentId);
        }

        try {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
            $paymentIntent->confirm();

            Log::info('StripeService: PaymentIntent confirmado', [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
            ]);

            return [
                'id' => $paymentIntent->id,
                'status' => $paymentIntent->status,
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('StripeService: Falha ao confirmar PaymentIntent', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'id' => $paymentIntentId,
                'status' => 'error',
                'amount' => 0,
                'currency' => 'brl',
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Refunds
    // =========================================================================

    /**
     * Cria um reembolso total ou parcial.
     *
     * @param  string     $paymentIntentId  ID do PaymentIntent original
     * @param  float|null $amount           Valor em reais para reembolso parcial (null = total)
     * @return array{id: string, status: string, amount: int, payment_intent: string}
     */
    public function createRefund(string $paymentIntentId, ?float $amount = null): array
    {
        $params = ['payment_intent' => $paymentIntentId];

        if ($amount !== null) {
            $params['amount'] = (int) round($amount * 100);
        }

        if (! $this->isConfigured()) {
            return $this->mockCreateRefund($paymentIntentId, $params);
        }

        try {
            $refund = \Stripe\Refund::create($params);

            Log::info('StripeService: Reembolso criado', [
                'id' => $refund->id,
                'amount' => $refund->amount,
                'payment_intent' => $refund->payment_intent,
            ]);

            return [
                'id' => $refund->id,
                'status' => $refund->status,
                'amount' => $refund->amount,
                'payment_intent' => $refund->payment_intent,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('StripeService: Falha ao criar reembolso', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'id' => null,
                'status' => 'error',
                'amount' => $params['amount'] ?? 0,
                'payment_intent' => $paymentIntentId,
                'error' => $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Customers
    // =========================================================================

    /**
     * Cria um Customer no Stripe vinculado ao User.
     *
     * @return string O stripe_customer_id criado
     */
    public function createCustomer(User $user): string
    {
        if (! $this->isConfigured()) {
            return $this->mockCreateCustomer($user);
        }

        try {
            $customer = \Stripe\Customer::create([
                'email' => $user->email,
                'name' => $user->name,
                'phone' => $user->phone,
                'metadata' => [
                    'user_id' => $user->id,
                    'cpf' => $user->cpf,
                ],
            ]);

            $user->update(['stripe_customer_id' => $customer->id]);

            Log::info('StripeService: Customer criado', [
                'user_id' => $user->id,
                'stripe_customer_id' => $customer->id,
            ]);

            return $customer->id;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('StripeService: Falha ao criar Customer', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // =========================================================================
    // Subscriptions
    // =========================================================================

    /**
     * Cria uma assinatura recorrente para o usuario.
     *
     * @param  string $priceId  ID do Price no Stripe (ex: price_xxx)
     * @return array{id: string, status: string, current_period_start: int, current_period_end: int, customer: string, client_secret: string|null}
     */
    public function createSubscription(User $user, string $priceId): array
    {
        if (! $this->isConfigured()) {
            return $this->mockCreateSubscription($user, $priceId);
        }

        try {
            // Garantir que o usuario tem customer_id
            $customerId = $user->stripe_customer_id ?? $this->createCustomer($user);

            $subscription = \Stripe\Subscription::create([
                'customer' => $customerId,
                'items' => [['price' => $priceId]],
                'trial_period_days' => 7,
                'payment_behavior' => 'default_incomplete',
                'expand' => ['latest_invoice.payment_intent'],
                'metadata' => [
                    'user_id' => $user->id,
                ],
            ]);

            // Salvar o subscription_id no usuario
            $user->update(['stripe_subscription_id' => $subscription->id]);

            Log::info('StripeService: Assinatura criada', [
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'status' => $subscription->status,
            ]);

            return [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'current_period_start' => $subscription->current_period_start,
                'current_period_end' => $subscription->current_period_end,
                'customer' => $subscription->customer,
                'client_secret' => $subscription->latest_invoice->payment_intent->client_secret ?? null,
            ];
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('StripeService: Falha ao criar assinatura', [
                'user_id' => $user->id,
                'price_id' => $priceId,
                'error' => $e->getMessage(),
            ]);

            return [
                'id' => null,
                'status' => 'error',
                'current_period_start' => 0,
                'current_period_end' => 0,
                'customer' => $user->stripe_customer_id ?? '',
                'client_secret' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Cancela uma assinatura existente.
     *
     * @param  string $subscriptionId  ID da assinatura no Stripe
     * @return bool   True se cancelou com sucesso
     */
    public function cancelSubscription(string $subscriptionId): bool
    {
        if (! $this->isConfigured()) {
            return $this->mockCancelSubscription($subscriptionId);
        }

        try {
            $subscription = \Stripe\Subscription::retrieve($subscriptionId);
            $subscription->cancel();

            Log::info('StripeService: Assinatura cancelada', [
                'subscription_id' => $subscriptionId,
            ]);

            return true;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            Log::error('StripeService: Falha ao cancelar assinatura', [
                'subscription_id' => $subscriptionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // =========================================================================
    // Webhooks
    // =========================================================================

    /**
     * Valida a assinatura de um webhook do Stripe.
     *
     * @return array|null  O payload do evento ou null se invalido
     */
    public function validateWebhookSignature(string $payload, string $sigHeader): ?array
    {
        if (! $this->isConfigured() || empty($this->webhookSecret)) {
            return $this->mockValidateWebhookSignature($payload);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $sigHeader,
                $this->webhookSecret
            );

            Log::info('StripeService: Webhook validado', [
                'event_type' => $event->type,
                'event_id' => $event->id,
            ]);

            return $event->toArray();
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('StripeService: Assinatura de webhook invalida', [
                'error' => $e->getMessage(),
            ]);

            return null;
        } catch (\UnexpectedValueException $e) {
            Log::warning('StripeService: Payload de webhook invalido', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // =========================================================================
    // Mock methods (fallback para desenvolvimento sem chaves)
    // =========================================================================

    private function mockCreatePaymentIntent(int $amountCents, string $currency, array $metadata): array
    {
        Log::warning('StripeService [MOCK]: createPaymentIntent - Stripe nao configurado, usando dados simulados', [
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'metadata' => $metadata,
        ]);

        $mockId = 'pi_mock_' . Str::random(24);

        return [
            'id' => $mockId,
            'client_secret' => $mockId . '_secret_' . Str::random(16),
            'status' => 'requires_payment_method',
            'amount' => $amountCents,
            'currency' => $currency,
        ];
    }

    private function mockConfirmPayment(string $paymentIntentId): array
    {
        Log::warning('StripeService [MOCK]: confirmPayment - Stripe nao configurado, usando dados simulados', [
            'payment_intent_id' => $paymentIntentId,
        ]);

        return [
            'id' => $paymentIntentId,
            'status' => 'succeeded',
            'amount' => 0,
            'currency' => 'brl',
        ];
    }

    private function mockCreateRefund(string $paymentIntentId, array $params): array
    {
        Log::warning('StripeService [MOCK]: createRefund - Stripe nao configurado, usando dados simulados', $params);

        $mockRefundId = 're_mock_' . Str::random(24);

        return [
            'id' => $mockRefundId,
            'status' => 'succeeded',
            'amount' => $params['amount'] ?? 0,
            'payment_intent' => $paymentIntentId,
        ];
    }

    private function mockCreateCustomer(User $user): string
    {
        Log::warning('StripeService [MOCK]: createCustomer - Stripe nao configurado, usando dados simulados', [
            'user_id' => $user->id,
            'email' => $user->email,
        ]);

        $mockCustomerId = 'cus_mock_' . Str::random(14);

        $user->update(['stripe_customer_id' => $mockCustomerId]);

        return $mockCustomerId;
    }

    private function mockCreateSubscription(User $user, string $priceId): array
    {
        Log::warning('StripeService [MOCK]: createSubscription - Stripe nao configurado, usando dados simulados', [
            'user_id' => $user->id,
            'price_id' => $priceId,
        ]);

        $mockSubId = 'sub_mock_' . Str::random(24);
        $now = now();

        $user->update(['stripe_subscription_id' => $mockSubId]);

        return [
            'id' => $mockSubId,
            'status' => 'trialing',
            'current_period_start' => $now->timestamp,
            'current_period_end' => $now->addDays(30)->timestamp,
            'customer' => $user->stripe_customer_id ?? 'cus_mock_' . Str::random(14),
            'client_secret' => null,
        ];
    }

    private function mockCancelSubscription(string $subscriptionId): bool
    {
        Log::warning('StripeService [MOCK]: cancelSubscription - Stripe nao configurado, usando dados simulados', [
            'subscription_id' => $subscriptionId,
        ]);

        return true;
    }

    private function mockValidateWebhookSignature(string $payload): ?array
    {
        Log::warning('StripeService [MOCK]: validateWebhookSignature - Stripe nao configurado, aceitando payload sem validacao');

        return json_decode($payload, true);
    }
}
