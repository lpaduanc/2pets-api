<?php

namespace Tests\Feature;

use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Subscription\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;

    private SubscriptionPlan $freePlan;

    private SubscriptionPlan $proPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();

        $this->freePlan = SubscriptionPlan::create([
            'name' => 'Gratuito',
            'slug' => 'free',
            // `tier` é o enum PlanTier (basic|pro|enterprise) e tem CHECK constraint no banco;
            // o nome comercial do plano vive no `slug`. Ver SubscriptionPlanSeeder.
            'tier' => 'basic',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'trial_days' => 0,
            'features' => ['basic_search', 'pet_profile'],
            'limits' => ['pets' => 2, 'favorites' => 5],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->proPlan = SubscriptionPlan::create([
            'name' => 'Premium',
            'slug' => 'premium',
            'tier' => 'pro',
            'monthly_price' => 29.90,
            'yearly_price' => 299.00,
            'trial_days' => 7,
            'features' => ['basic_search', 'pet_profile', 'advanced_search', 'health_reminders', 'expense_tracker'],
            'limits' => ['pets' => -1, 'favorites' => -1],
            'is_active' => true,
            'sort_order' => 2,
        ]);
    }

    // Não há mock de BillingService: a classe é `final` (Mockery não consegue substituí-la) e,
    // mais importante, não precisa de mock. Os três métodos têm early return quando não há
    // `stripe_price_id` / `gateway_subscription_id` — e os planos deste teste não têm nenhum dos
    // dois, então o serviço real roda o caminho local e nunca chama o Stripe. Mockar aqui
    // esconderia o comportamento de ativação que os testes deveriam justamente provar.

    // ---------------------------------------------------------------
    // View plans
    // ---------------------------------------------------------------

    public function test_user_can_view_plans(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->getJson('/api/subscriptions/plans');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'monthly_price', 'yearly_price', 'features', 'is_active'],
                ],
            ]);

        // Both plans should be returned (both are active)
        $this->assertCount(2, $response->json('data'));
    }

    // ---------------------------------------------------------------
    // View current subscription
    // ---------------------------------------------------------------

    public function test_user_can_view_current_subscription(): void
    {
        Sanctum::actingAs($this->tutor);

        // Create an active subscription for the user
        $subscription = Subscription::create([
            'user_id' => $this->tutor->id,
            'subscription_plan_id' => $this->proPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);

        $response = $this->getJson('/api/subscriptions/current');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => ['id', 'user_id', 'subscription_plan_id', 'status', 'billing_cycle'],
            ]);

        $this->assertEquals($subscription->id, $response->json('data.id'));
        $this->assertEquals('active', $response->json('data.status'));
    }

    // ---------------------------------------------------------------
    // Subscribe to plan
    // ---------------------------------------------------------------

    public function test_user_can_subscribe_to_plan(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/subscriptions/subscribe', [
            'plan_id' => $this->proPlan->id,
            'billing_cycle' => 'monthly',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'user_id', 'subscription_plan_id', 'status'],
            ]);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->tutor->id,
            'subscription_plan_id' => $this->proPlan->id,
        ]);

        // Since the Pro plan has trial_days > 0, the subscription should start as trialing
        $this->assertEquals('trialing', $response->json('data.status'));
    }

    // ---------------------------------------------------------------
    // Cancel subscription
    // ---------------------------------------------------------------

    public function test_user_can_cancel_subscription(): void
    {
        Sanctum::actingAs($this->tutor);

        Subscription::create([
            'user_id' => $this->tutor->id,
            'subscription_plan_id' => $this->proPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);

        $response = $this->postJson('/api/subscriptions/cancel', [
            'immediately' => true,
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Subscription cancelled successfully']);

        $this->assertDatabaseHas('subscriptions', [
            'user_id' => $this->tutor->id,
            'status' => 'cancelled',
        ]);
    }

    // ---------------------------------------------------------------
    // Feature access check
    // ---------------------------------------------------------------

    public function test_can_check_feature_access(): void
    {
        Sanctum::actingAs($this->tutor);

        // Create an active subscription with the pro plan
        Subscription::create([
            'user_id' => $this->tutor->id,
            'subscription_plan_id' => $this->proPlan->id,
            'status' => 'active',
            'billing_cycle' => 'monthly',
            'starts_at' => now(),
        ]);

        // Check a feature that IS included in the pro plan
        $response = $this->getJson('/api/subscriptions/check-feature/advanced_search');

        $response->assertOk()
            ->assertJson([
                'has_access' => true,
                'feature' => 'advanced_search',
            ]);

        // Check a feature that is NOT included in the pro plan
        $response = $this->getJson('/api/subscriptions/check-feature/nonexistent_feature');

        $response->assertOk()
            ->assertJson([
                'has_access' => false,
                'feature' => 'nonexistent_feature',
            ]);
    }
}
