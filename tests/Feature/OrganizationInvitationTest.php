<?php

namespace Tests\Feature;

use App\Enums\OrganizationRole;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMember;
use App\Models\Professional;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Fase 2 do split Pessoa/Organização — convites. Regra regulatória não-negociável: aceitar
 * convite de `veterinarian` exige `professionals.crmv` preenchido
 * (`docs/rbac-clinica-autoria-e-staff.md`).
 */
class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Mail::fake();
    }

    public function test_owner_can_invite_a_new_member(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/organizations/{$organization->id}/invitations", [
                'email' => 'novo@2pets.com.br',
                'role' => 'assistant',
            ]);

        $response->assertCreated()->assertJsonStructure(['data' => ['id', 'email', 'role', 'expires_at']]);
        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'novo@2pets.com.br',
            'role' => 'assistant',
        ]);
        Mail::assertSent(OrganizationInvitationMail::class);
    }

    public function test_owner_can_invite_another_owner(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/organizations/{$organization->id}/invitations", [
                'email' => 'novo-owner@2pets.com.br',
                'role' => 'owner',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('organization_invitations', [
            'organization_id' => $organization->id,
            'email' => 'novo-owner@2pets.com.br',
            'role' => 'owner',
        ]);
    }

    public function test_non_owner_cannot_invite(): void
    {
        $organization = Organization::factory()->create();
        $outsider = User::factory()->tutor()->create();

        $response = $this->actingAs($outsider, 'sanctum')
            ->postJson("/api/organizations/{$organization->id}/invitations", [
                'email' => 'novo@2pets.com.br',
                'role' => 'assistant',
            ]);

        $response->assertForbidden();
    }

    public function test_cannot_invite_email_already_an_active_member(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $activeMemberUser = User::factory()->tutor()->create(['email' => 'membro@2pets.com.br']);
        OrganizationMember::factory()->for($organization, 'organization')->create(['user_id' => $activeMemberUser->id]);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/organizations/{$organization->id}/invitations", [
                'email' => 'membro@2pets.com.br',
                'role' => 'assistant',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_cannot_invite_same_email_twice_while_pending(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        OrganizationInvitation::factory()->for($organization, 'organization')->create(['email' => 'pendente@2pets.com.br']);

        $response = $this->actingAs($owner, 'sanctum')
            ->postJson("/api/organizations/{$organization->id}/invitations", [
                'email' => 'pendente@2pets.com.br',
                'role' => 'assistant',
            ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_owner_can_list_pending_invitations(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        OrganizationInvitation::factory()->for($organization, 'organization')->create(['email' => 'pendente@2pets.com.br']);
        OrganizationInvitation::factory()->for($organization, 'organization')->accepted()->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->getJson("/api/organizations/{$organization->id}/invitations");

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonFragment(['email' => 'pendente@2pets.com.br']);
    }

    public function test_owner_can_revoke_an_invitation(): void
    {
        $organization = Organization::factory()->create();
        $owner = $this->createOwner($organization);
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create();

        $response = $this->actingAs($owner, 'sanctum')
            ->deleteJson("/api/organizations/{$organization->id}/invitations/{$invitation->id}");

        $response->assertNoContent();
        $this->assertNotNull($invitation->fresh()->revoked_at);
    }

    public function test_invitation_belonging_to_another_organization_returns_not_found(): void
    {
        $organizationA = Organization::factory()->create();
        $organizationB = Organization::factory()->create();
        $ownerA = $this->createOwner($organizationA);
        $invitationOfB = OrganizationInvitation::factory()->for($organizationB, 'organization')->create();

        $response = $this->actingAs($ownerA, 'sanctum')
            ->deleteJson("/api/organizations/{$organizationA->id}/invitations/{$invitationOfB->id}");

        $response->assertNotFound();
    }

    public function test_public_show_returns_invitation_details_without_auth(): void
    {
        $organization = Organization::factory()->create(['business_name' => 'Clínica Feliz']);
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create([
            'role' => OrganizationRole::VETERINARIAN->value,
            'email' => 'convidado@2pets.com.br',
        ]);

        $response = $this->getJson("/api/organization-invitations/{$invitation->token}");

        $response->assertOk()->assertExactJson([
            'data' => [
                'organization_name' => 'Clínica Feliz',
                'role' => 'veterinarian',
                'role_label' => 'Veterinário',
                'email' => 'convidado@2pets.com.br',
                'expired' => false,
            ],
        ]);
    }

    public function test_public_show_unknown_token_returns_not_found(): void
    {
        $response = $this->getJson('/api/organization-invitations/token-que-nao-existe');

        $response->assertNotFound();
    }

    public function test_public_show_reports_expired_invitation(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()->expired()->for($organization, 'organization')->create();

        $response = $this->getJson("/api/organization-invitations/{$invitation->token}");

        $response->assertOk()->assertJsonPath('data.expired', true);
    }

    public function test_accept_requires_authentication(): void
    {
        $organization = Organization::factory()->create();
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create();

        $response = $this->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $response->assertUnauthorized();
    }

    public function test_accept_creates_membership_and_grants_spatie_role_for_non_clinical_role(): void
    {
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->tutor()->create(['email' => 'convidado@2pets.com.br']);
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create([
            'email' => 'convidado@2pets.com.br',
            'role' => OrganizationRole::RECEPTIONIST->value,
        ]);

        $response = $this->actingAs($invitedUser, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $response->assertOk()->assertExactJson([
            'data' => ['organization_id' => $organization->id, 'role' => 'receptionist'],
        ]);
        $this->assertDatabaseHas('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitedUser->id,
            'role' => 'receptionist',
        ]);
        $this->assertTrue($invitedUser->fresh()->hasRole('petshop_staff'));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_accept_fails_when_authenticated_user_email_differs_from_invitation(): void
    {
        $organization = Organization::factory()->create();
        $someoneElse = User::factory()->tutor()->create(['email' => 'outra-pessoa@2pets.com.br']);
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create([
            'email' => 'convidado@2pets.com.br',
        ]);

        $response = $this->actingAs($someoneElse, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $response->assertForbidden();
    }

    public function test_accept_veterinarian_role_requires_crmv(): void
    {
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->tutor()->create(['email' => 'vet-sem-crmv@2pets.com.br']);
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create([
            'email' => 'vet-sem-crmv@2pets.com.br',
            'role' => OrganizationRole::VETERINARIAN->value,
        ]);

        $response = $this->actingAs($invitedUser, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $response->assertStatus(422)->assertJsonValidationErrors('role');
        $this->assertDatabaseMissing('organization_members', [
            'organization_id' => $organization->id,
            'user_id' => $invitedUser->id,
        ]);
        $this->assertFalse($invitedUser->fresh()->hasRole('clinic_vet'));
    }

    public function test_accept_veterinarian_role_succeeds_with_crmv(): void
    {
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->tutor()->create(['email' => 'vet-com-crmv@2pets.com.br']);
        Professional::factory()->veterinarian()->create(['user_id' => $invitedUser->id, 'crmv' => '54321']);
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create([
            'email' => 'vet-com-crmv@2pets.com.br',
            'role' => OrganizationRole::VETERINARIAN->value,
        ]);

        $response = $this->actingAs($invitedUser, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $response->assertOk();
        $this->assertTrue($invitedUser->fresh()->hasRole('clinic_vet'));
    }

    public function test_accept_is_idempotent(): void
    {
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->tutor()->create(['email' => 'convidado@2pets.com.br']);
        $invitation = OrganizationInvitation::factory()->for($organization, 'organization')->create([
            'email' => 'convidado@2pets.com.br',
            'role' => OrganizationRole::ASSISTANT->value,
        ]);

        $this->actingAs($invitedUser, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept")
            ->assertOk();

        $second = $this->actingAs($invitedUser, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $second->assertOk();
        $this->assertSame(
            1,
            OrganizationMember::where('organization_id', $organization->id)
                ->where('user_id', $invitedUser->id)
                ->count()
        );
    }

    public function test_accept_expired_invitation_returns_unprocessable(): void
    {
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->tutor()->create(['email' => 'convidado@2pets.com.br']);
        $invitation = OrganizationInvitation::factory()->expired()->for($organization, 'organization')->create([
            'email' => 'convidado@2pets.com.br',
        ]);

        $response = $this->actingAs($invitedUser, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $response->assertStatus(422);
    }

    public function test_accept_revoked_invitation_returns_unprocessable(): void
    {
        $organization = Organization::factory()->create();
        $invitedUser = User::factory()->tutor()->create(['email' => 'convidado@2pets.com.br']);
        $invitation = OrganizationInvitation::factory()->revoked()->for($organization, 'organization')->create([
            'email' => 'convidado@2pets.com.br',
        ]);

        $response = $this->actingAs($invitedUser, 'sanctum')
            ->postJson("/api/organization-invitations/{$invitation->token}/accept");

        $response->assertStatus(422);
    }

    private function createOwner(Organization $organization): User
    {
        $owner = User::factory()->tutor()->create();

        OrganizationMember::factory()->owner()->for($organization, 'organization')->create([
            'user_id' => $owner->id,
        ]);

        return $owner;
    }
}
