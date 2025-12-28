<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\PaymentWebhook;
use App\Services\Payment\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 300; // 5 minutes

    public function __construct(
        private readonly int $webhookId
    ) {}

    public function handle(PaymentService $paymentService): void
    {
        $webhook = PaymentWebhook::findOrFail($this->webhookId);

        if ($webhook->processed) {
            return;
        }

        try {
            $paymentId = $webhook->gateway_payment_id;

            if (!$paymentId) {
                Log::warning('Webhook has no payment ID', ['webhook_id' => $webhook->id]);
                return;
            }

            $payment = Payment::where('gateway_payment_id', $paymentId)->first();

            if (!$payment) {
                Log::warning('Payment not found for webhook', [
                    'webhook_id' => $webhook->id,
                    'payment_id' => $paymentId,
                ]);
                return;
            }

            $paymentService->updatePaymentStatus($payment);

            $webhook->update([
                'processed' => true,
                'processed_at' => now(),
            ]);

            Log::info('Webhook processed successfully', [
                'webhook_id' => $webhook->id,
                'payment_id' => $payment->id,
                'new_status' => $payment->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Webhook processing failed', [
                'webhook_id' => $webhook->id,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Will trigger retry
        }
    }
}

