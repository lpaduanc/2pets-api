<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\Review;
use App\Models\ReviewHelpfulVote;
use App\Models\ReviewResponse;
use App\Models\User;
use App\Services\FileUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $tutor;
    private User $professionalUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tutor = User::factory()->tutor()->create();

        $this->professionalUser = User::factory()->professional()->create();

        Professional::factory()->create([
            'user_id' => $this->professionalUser->id,
        ]);

        // Mock FileUploadService to avoid S3/disk operations in tests
        $mock = \Mockery::mock(FileUploadService::class);
        $mock->shouldReceive('uploadFile')->andReturn('reviews/mock_photo.jpg');
        $this->app->instance(FileUploadService::class, $mock);
    }

    // ---------------------------------------------------------------
    // View reviews
    // ---------------------------------------------------------------

    public function test_user_can_view_professional_reviews(): void
    {
        Sanctum::actingAs($this->tutor);

        // Create some visible reviews for the professional
        Review::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'rating' => 5,
            'comment' => 'Excelente atendimento!',
            'is_visible' => true,
            'is_verified' => true,
            'helpful_count' => 0,
        ]);

        $secondTutor = User::factory()->tutor()->create();
        Review::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $secondTutor->id,
            'rating' => 4,
            'comment' => 'Muito bom.',
            'is_visible' => true,
            'is_verified' => false,
            'helpful_count' => 0,
        ]);

        $response = $this->getJson("/api/reviews/professional/{$this->professionalUser->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'stats' => ['average_rating', 'total_reviews', 'distribution'],
            ]);
    }

    // ---------------------------------------------------------------
    // Create review
    // ---------------------------------------------------------------

    public function test_authenticated_user_can_create_review(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/reviews', [
            'professional_id' => $this->professionalUser->id,
            'rating' => 5,
            'comment' => 'Profissional excelente, super recomendo!',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => ['id', 'professional_id', 'client_id', 'rating', 'comment'],
            ]);

        $this->assertDatabaseHas('reviews', [
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'rating' => 5,
        ]);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_review_requires_rating(): void
    {
        Sanctum::actingAs($this->tutor);

        $response = $this->postJson('/api/reviews', [
            'professional_id' => $this->professionalUser->id,
            'comment' => 'Sem nota',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }

    // ---------------------------------------------------------------
    // Professional response
    // ---------------------------------------------------------------

    public function test_professional_can_respond_to_review(): void
    {
        // Create a review from the tutor
        $review = Review::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $this->tutor->id,
            'rating' => 4,
            'comment' => 'Bom atendimento',
            'is_visible' => true,
            'is_verified' => false,
            'helpful_count' => 0,
        ]);

        // Act as the professional to respond
        Sanctum::actingAs($this->professionalUser);

        $response = $this->postJson("/api/reviews/{$review->id}/response", [
            'response' => 'Obrigado pela avaliacao! Volte sempre.',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Response added successfully']);

        $this->assertDatabaseHas('review_responses', [
            'review_id' => $review->id,
            'professional_id' => $this->professionalUser->id,
        ]);
    }

    // ---------------------------------------------------------------
    // Flag review
    // ---------------------------------------------------------------

    public function test_user_can_flag_review(): void
    {
        Sanctum::actingAs($this->tutor);

        $otherTutor = User::factory()->tutor()->create();

        $review = Review::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $otherTutor->id,
            'rating' => 1,
            'comment' => 'Conteudo inapropriado',
            'is_visible' => true,
            'is_verified' => false,
            'helpful_count' => 0,
        ]);

        $response = $this->postJson("/api/reviews/{$review->id}/flag", [
            'reason' => 'Review com linguagem ofensiva',
        ]);

        $response->assertOk()
            ->assertJson(['message' => 'Review flagged for moderation']);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'is_flagged' => true,
        ]);
    }

    // ---------------------------------------------------------------
    // Toggle helpful
    // ---------------------------------------------------------------

    public function test_user_can_toggle_helpful(): void
    {
        Sanctum::actingAs($this->tutor);

        $otherTutor = User::factory()->tutor()->create();

        $review = Review::create([
            'professional_id' => $this->professionalUser->id,
            'client_id' => $otherTutor->id,
            'rating' => 5,
            'comment' => 'Atendimento incrivel!',
            'is_visible' => true,
            'is_verified' => true,
            'helpful_count' => 0,
        ]);

        // Mark as helpful
        $response = $this->postJson("/api/reviews/{$review->id}/helpful");

        $response->assertOk()
            ->assertJson([
                'is_helpful' => true,
                'helpful_count' => 1,
            ]);

        $this->assertDatabaseHas('review_helpful_votes', [
            'review_id' => $review->id,
            'user_id' => $this->tutor->id,
        ]);

        // Toggle again to remove
        $response = $this->postJson("/api/reviews/{$review->id}/helpful");

        $response->assertOk()
            ->assertJson([
                'is_helpful' => false,
                'helpful_count' => 0,
            ]);

        $this->assertDatabaseMissing('review_helpful_votes', [
            'review_id' => $review->id,
            'user_id' => $this->tutor->id,
        ]);
    }
}
