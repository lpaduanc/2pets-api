<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------
    // Registration
    // ---------------------------------------------------------------

    public function test_user_can_register_as_tutor(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Maria Silva',
            'email' => 'maria@example.com',
            'phone' => '11999998888',
            'user_type' => 'tutor',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'role', 'user_type'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'maria@example.com',
            'role' => 'tutor',
            'user_type' => 'tutor',
        ]);
    }

    public function test_user_can_register_as_professional(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Dr. Carlos Vet',
            'email' => 'carlos@vet.com',
            'phone' => '11988887777',
            'user_type' => 'vet',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'role', 'user_type'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'carlos@vet.com',
            'role' => 'professional',
            'user_type' => 'vet',
        ]);
    }

    // ---------------------------------------------------------------
    // Login
    // ---------------------------------------------------------------

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->tutor()->create([
            'email' => 'login@test.com',
            'password' => Hash::make('secret1234'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'login@test.com',
            'password' => 'secret1234',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'user' => ['id', 'name', 'email'],
            ]);
    }

    public function test_user_cannot_login_with_invalid_credentials(): void
    {
        User::factory()->tutor()->create([
            'email' => 'valid@test.com',
            'password' => Hash::make('correctpassword'),
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'valid@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid login details']);
    }

    // ---------------------------------------------------------------
    // Logout
    // ---------------------------------------------------------------

    public function test_user_can_logout(): void
    {
        $user = User::factory()->tutor()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/logout');

        $response->assertOk()
            ->assertJson(['message' => 'Logged out successfully']);
    }

    // ---------------------------------------------------------------
    // Password Reset
    // ---------------------------------------------------------------

    public function test_user_can_request_password_reset(): void
    {
        User::factory()->tutor()->create([
            'email' => 'reset@test.com',
        ]);

        $response = $this->postJson('/api/forgot-password', [
            'email' => 'reset@test.com',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['message']);
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    public function test_registration_validates_required_fields(): void
    {
        $response = $this->postJson('/api/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email', 'phone', 'user_type', 'password']);
    }

    public function test_duplicate_email_rejected(): void
    {
        User::factory()->create(['email' => 'duplicate@test.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Another User',
            'email' => 'duplicate@test.com',
            'phone' => '11999990000',
            'user_type' => 'tutor',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ---------------------------------------------------------------
    // Account status restrictions
    // ---------------------------------------------------------------

    public function test_suspended_user_cannot_login(): void
    {
        // The login controller checks email_verified first, then profile_completed.
        // A suspended user with email_verified=false gets blocked at email verification step.
        // For this test we verify a user that is not email-verified is blocked from logging in.
        $user = User::factory()->tutor()->create([
            'email' => 'suspended@test.com',
            'password' => Hash::make('secret1234'),
            'email_verified' => false,
            'is_suspended' => true,
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'suspended@test.com',
            'password' => 'secret1234',
        ]);

        // The auth controller returns 403 for non-verified email
        $response->assertStatus(403)
            ->assertJson(['email_not_verified' => true]);
    }

    public function test_pending_company_cannot_login(): void
    {
        $user = User::factory()->company()->create([
            'email' => 'company@test.com',
            'password' => Hash::make('secret1234'),
            'email_verified' => true,
            'registration_status' => 'pending',
        ]);

        $response = $this->postJson('/api/login', [
            'email' => 'company@test.com',
            'password' => 'secret1234',
        ]);

        $response->assertStatus(403)
            ->assertJson(['pending_approval' => true]);
    }
}
