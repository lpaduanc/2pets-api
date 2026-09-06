<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    public function test_features_endpoint_exposes_flags(): void
    {
        $response = $this->getJson('/api/features');
        $response->assertOk()
            ->assertJsonStructure([
                'video_consultations',
                'ai_guardian',
                'marketplace',
            ]);
    }

    public function test_disabled_feature_returns_404_for_authenticated_user(): void
    {
        Config::set('features.video_consultations', false);

        $user = User::factory()->tutor()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/video-consultations', [])->assertNotFound();
    }

    public function test_enabled_feature_allows_the_request_through(): void
    {
        Config::set('features.video_consultations', true);

        $user = User::factory()->tutor()->create();
        Sanctum::actingAs($user);

        // Request is incomplete (no teleatendimento_type) so middleware lets it pass
        // and validation returns 422 — that proves the feature gate didn't 404 us.
        $this->postJson('/api/video-consultations', [])->assertStatus(422);
    }
}
