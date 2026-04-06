<?php

namespace Tests\Feature;

use App\Contracts\PaymentGatewayInterface;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private User $professionalUser;
    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();

        $this->professionalUser = User::factory()->professional()->create();

        Professional::factory()->create([
            'user_id' => $this->professionalUser->id,
        ]);

        // Create a pending invoice for the tutor
        $this->invoice = Invoice::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'invoice_number' => 'INV-TEST-001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'items' => [
                ['description' => 'Consulta veterinaria', 'quantity' => 1, 'unit_price' => 150, 'total' => 150],
            ],
            'subtotal' => 150.00,
            'discount' => 0,
            'tax' => 0,
            'total' => 150.00,
            'status' => 'pending',
        ]);

        // Mock the payment gateway to avoid external API calls
        $this->mockPaymentGateway();
    }

    private function mockPaymentGateway(): void
    {
        $mock = \Mockery::mock(PaymentGatewayInterface::class);

        $mock->shouldReceive('createPayment')
            ->andReturn([
                'success' => true,
                'payment_id' => 'mock_payment_123',
                'status' => 'pending',
                'response' => ['id' => 'mock_payment_123'],
                'qr_code' => 'mock_qr_code_string',
                'qr_code_base64' => 'base64data',
                'ticket_url' => null,
            ]);

        $mock->shouldReceive('getPaymentStatus')
            ->andReturn('pending');

        $mock->shouldReceive('refundPayment')
            ->andReturn(true);

        $this->app->instance(PaymentGatewayInterface::class, $mock);
    }

    // ---------------------------------------------------------------
    // Create payment
    // ---------------------------------------------------------------

    public function test_authenticated_user_can_create_payment(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/payments', [
            'invoice_id' => $this->invoice->id,
            'payment_method' => 'pix',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'status', 'amount', 'method'],
            ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => $this->invoice->id,
            'user_id' => $this->tutor->id,
            'method' => 'pix',
        ]);
    }

    public function test_unauthenticated_user_cannot_create_payment(): void
    {
        $response = $this->postJson('/api/payments', [
            'invoice_id' => $this->invoice->id,
            'payment_method' => 'pix',
        ]);

        $response->assertStatus(401);
    }

    // ---------------------------------------------------------------
    // Check payment status
    // ---------------------------------------------------------------

    public function test_user_can_check_payment_status(): void
    {
        Sanctum::actingAs($this->tutor);

        $payment = Payment::create([
            'invoice_id' => $this->invoice->id,
            'user_id' => $this->tutor->id,
            'gateway' => 'mercadopago',
            'gateway_payment_id' => 'mock_123',
            'method' => 'pix',
            'amount' => 150.00,
            'status' => 'pending',
            'installments' => 1,
        ]);

        $response = $this->getJson("/api/payments/{$payment->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'invoice_id', 'status', 'amount', 'method'],
            ]);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_payment_requires_amount_and_method(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/payments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['invoice_id', 'payment_method']);
    }

    public function test_invalid_payment_method_rejected(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/payments', [
            'invoice_id' => $this->invoice->id,
            'payment_method' => 'bitcoin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }
}
