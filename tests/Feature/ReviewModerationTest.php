<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pre-moderação (CLAUDE.md MVP "Moderação de avaliações (painel admin)"):
 * avaliação criada não aparece em público até admin aprovar.
 */
class ReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_review_starts_hidden_and_pending(): void
    {
        $tutor = User::factory()->tutor()->create();
        $vet = User::factory()->professional()->create();

        Sanctum::actingAs($tutor);

        $response = $this->postJson('/api/reviews', [
            'professional_id' => $vet->id,
            'rating' => 5,
            'comment' => 'Excelente atendimento',
        ]);

        $response->assertCreated();

        $review = Review::latest('id')->first();
        $this->assertEquals(Review::MODERATION_PENDING, $review->moderation_status);
        $this->assertFalse($review->is_visible);
    }

    public function test_pending_review_does_not_appear_in_public_list(): void
    {
        $vet = User::factory()->professional()->create();
        Review::factoryPending($vet->id);

        $tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($tutor);

        $response = $this->getJson("/api/reviews/professional/{$vet->id}");

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data') ?? $response->json('data'));
    }

    public function test_admin_approve_makes_review_visible(): void
    {
        $vet = User::factory()->professional()->create();
        $review = Review::factoryPending($vet->id);
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $this->postJson("/api/reviews/{$review->id}/moderate", [
            'approve' => true,
            'note' => 'Aprovada após revisão',
        ])->assertOk();

        $review->refresh();
        $this->assertEquals(Review::MODERATION_APPROVED, $review->moderation_status);
        $this->assertTrue($review->is_visible);
        $this->assertEquals($admin->id, $review->moderated_by);
    }

    public function test_non_admin_cannot_moderate(): void
    {
        $vet = User::factory()->professional()->create();
        $review = Review::factoryPending($vet->id);
        $tutor = User::factory()->tutor()->create();

        Sanctum::actingAs($tutor);
        $this->postJson("/api/reviews/{$review->id}/moderate", ['approve' => true])
            ->assertForbidden();
    }
}
