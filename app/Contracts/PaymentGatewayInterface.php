<?php

namespace App\Contracts;

use App\Enums\PaymentMethod;

interface PaymentGatewayInterface
{
    public function createPayment(
        float $amount,
        PaymentMethod $method,
        array $payerData,
        ?int $installments = 1,
        ?array $metadata = []
    ): array;

    public function getPaymentStatus(string $paymentId): string;

    public function refundPayment(string $paymentId, ?float $amount = null): bool;

    public function verifyWebhookSignature(string $payload, string $signature): bool;

    public function parseWebhookPayload(array $payload): array;
}

