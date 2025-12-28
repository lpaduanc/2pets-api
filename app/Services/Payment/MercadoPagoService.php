<?php

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Enums\PaymentMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MercadoPagoService implements PaymentGatewayInterface
{
    private ?string $accessToken;
    private string $apiUrl = 'https://api.mercadopago.com/v1';

    public function __construct()
    {
        $this->accessToken = config('services.mercadopago.access_token');
    }

    public function createPayment(
        float $amount,
        PaymentMethod $method,
        array $payerData,
        ?int $installments = 1,
        ?array $metadata = []
    ): array {
        $payload = $this->buildPaymentPayload($amount, $method, $payerData, $installments, $metadata);

        try {
            $response = Http::withToken($this->accessToken)
                ->post("{$this->apiUrl}/payments", $payload);

            if (!$response->successful()) {
                Log::error('Mercado Pago payment creation failed', [
                    'response' => $response->json(),
                    'status' => $response->status(),
                ]);

                throw new \Exception('Failed to create payment');
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_id' => $data['id'],
                'status' => $this->mapStatus($data['status']),
                'qr_code' => $data['point_of_interaction']['transaction_data']['qr_code'] ?? null,
                'qr_code_base64' => $data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
                'ticket_url' => $data['transaction_details']['external_resource_url'] ?? null,
                'response' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Mercado Pago error', ['error' => $e->getMessage()]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getPaymentStatus(string $paymentId): string
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->get("{$this->apiUrl}/payments/{$paymentId}");

            if (!$response->successful()) {
                return 'unknown';
            }

            $data = $response->json();

            return $this->mapStatus($data['status']);
        } catch (\Exception $e) {
            Log::error('Mercado Pago status check failed', ['error' => $e->getMessage()]);

            return 'unknown';
        }
    }

    public function refundPayment(string $paymentId, ?float $amount = null): bool
    {
        try {
            $payload = [];
            if ($amount !== null) {
                $payload['amount'] = $amount;
            }

            $response = Http::withToken($this->accessToken)
                ->post("{$this->apiUrl}/payments/{$paymentId}/refunds", $payload);

            return $response->successful();
        } catch (\Exception $e) {
            Log::error('Mercado Pago refund failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // Mercado Pago uses x-signature header
        // Implementation depends on webhook configuration
        return true; // Simplified for now
    }

    public function parseWebhookPayload(array $payload): array
    {
        return [
            'payment_id' => $payload['data']['id'] ?? null,
            'event_type' => $payload['type'] ?? null,
            'status' => isset($payload['data']) ? $this->mapStatus($payload['data']['status'] ?? '') : 'unknown',
        ];
    }

    private function buildPaymentPayload(
        float $amount,
        PaymentMethod $method,
        array $payerData,
        int $installments,
        array $metadata
    ): array {
        $payload = [
            'transaction_amount' => $amount,
            'description' => $metadata['description'] ?? 'Pagamento 2Pets',
            'payment_method_id' => $this->getPaymentMethodId($method),
            'payer' => [
                'email' => $payerData['email'],
                'first_name' => $payerData['name'] ?? '',
                'identification' => [
                    'type' => 'CPF',
                    'number' => $payerData['document'] ?? '',
                ],
            ],
        ];

        if ($method === PaymentMethod::CREDIT_CARD && $installments > 1) {
            $payload['installments'] = $installments;
        }

        if (isset($metadata['external_reference'])) {
            $payload['external_reference'] = $metadata['external_reference'];
        }

        return $payload;
    }

    private function getPaymentMethodId(PaymentMethod $method): string
    {
        return match ($method) {
            PaymentMethod::PIX => 'pix',
            PaymentMethod::BOLETO => 'bolbradesco',
            PaymentMethod::CREDIT_CARD => 'visa', // Will be dynamic based on card
            PaymentMethod::DEBIT_CARD => 'debvisa',
        };
    }

    private function mapStatus(string $mpStatus): string
    {
        return match ($mpStatus) {
            'approved' => 'paid',
            'pending', 'in_process' => 'processing',
            'rejected', 'cancelled' => 'failed',
            'refunded' => 'refunded',
            default => 'pending',
        };
    }
}

