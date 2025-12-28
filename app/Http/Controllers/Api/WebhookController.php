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

    public function mercadopago(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::info('Mercado Pago webhook received', ['payload' => $payload]);

        // Verify signature (if configured)
        $signature = $request->header('x-signature', '');
        if ($signature && !$this->gateway->verifyWebhookSignature(json_encode($payload), $signature)) {
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

