<?php

namespace App\Http\Controllers\Api;

use App\Contracts\PaymentGatewayInterface;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Models\PaymentWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayInterface $gateway
    ) {}

    public function stripe(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        Log::info('Stripe webhook received');

        // Verify Stripe signature
        $webhookSecret = config('services.stripe.webhook_secret');
        if ($webhookSecret && $sigHeader) {
            try {
                // If stripe/stripe-php is installed, use it for verification
                if (class_exists(\Stripe\Webhook::class)) {
                    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
                    $eventData = $event->toArray();
                } else {
                    $eventData = json_decode($payload, true);
                }
            } catch (\Exception $e) {
                Log::warning('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

                return response()->json(['error' => 'Invalid signature'], 400);
            }
        } else {
            $eventData = json_decode($payload, true);
        }

        $webhook = PaymentWebhook::create([
            'gateway' => 'stripe',
            'event_type' => $eventData['type'] ?? 'unknown',
            'gateway_payment_id' => $eventData['data']['object']['id'] ?? null,
            'payload' => $eventData,
        ]);

        ProcessPaymentWebhook::dispatch($webhook->id);

        return response()->json(['success' => true]);
    }

    public function mercadopago(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Mercado Pago webhook received', ['payload' => $payload]);

        // Verify signature (if configured)
        $signature = $request->header('x-signature', '');
        if ($signature && ! $this->gateway->verifyWebhookSignature(json_encode($payload), $signature)) {
            Log::warning('Invalid webhook signature');

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Parse webhook data
        $parsed = $this->gateway->parseWebhookPayload($payload);

        // Store webhook for processing
        $webhook = PaymentWebhook::create([
            'gateway' => 'mercadopago',
            'event_type' => $parsed['event_type'] ?? 'unknown',
            'gateway_payment_id' => $parsed['payment_id'] ?? null,
            'payload' => $payload,
        ]);

        // Dispatch job to process webhook
        ProcessPaymentWebhook::dispatch($webhook->id);

        return response()->json(['success' => true]);
    }
}
