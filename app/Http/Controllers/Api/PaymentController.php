<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\CreatePaymentRequest;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService
    ) {}

    public function create(CreatePaymentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $invoice = Invoice::findOrFail($validated['invoice_id']);

        // Verify user owns this invoice
        if ($invoice->client_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Check if already paid
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'Invoice already paid'], 422);
        }

        try {
            $method = PaymentMethod::from($validated['payment_method']);
            $installments = $validated['installments'] ?? 1;

            // Validate installments
            if ($installments > 1 && ! $method->allowsInstallments()) {
                return response()->json([
                    'message' => 'Installments not allowed for this payment method',
                ], 422);
            }

            $payment = $this->paymentService->createPayment(
                $invoice,
                $request->user(),
                $method,
                $installments
            );

            return response()->json([
                'message' => 'Payment created successfully',
                'data' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'amount' => $payment->amount,
                    'method' => $payment->method,
                    'qr_code' => $payment->metadata['qr_code'] ?? null,
                    'qr_code_base64' => $payment->metadata['qr_code_base64'] ?? null,
                    'ticket_url' => $payment->metadata['ticket_url'] ?? null,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Payment creation failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $payment = Payment::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->with('invoice')
            ->firstOrFail();

        return response()->json(['data' => $payment]);
    }

    public function validateCoupon(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20',
        ]);

        $coupon = Coupon::where('code', strtoupper($validated['code']))->first();

        if (! $coupon || ! $coupon->isValid()) {
            return response()->json([
                'valid' => false,
                'message' => 'Cupom invalido ou expirado',
            ]);
        }

        return response()->json([
            'valid' => true,
            'code' => $coupon->code,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'discount_amount' => $coupon->discount_type === 'fixed'
                ? (float) $coupon->discount_value
                : null,
        ]);
    }

    public function refund(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'nullable|numeric|min:0',
        ]);

        $payment = Payment::findOrFail($id);

        // Only professional/admin can refund
        if ($request->user()->role !== 'professional' && $request->user()->role !== 'admin') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            $success = $this->paymentService->refundPayment(
                $payment,
                $validated['amount'] ?? null
            );

            if ($success) {
                return response()->json(['message' => 'Payment refunded successfully']);
            }

            return response()->json(['message' => 'Refund failed'], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Refund failed',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
