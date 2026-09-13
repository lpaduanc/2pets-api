<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Document;
use App\Models\Hospitalization;
use App\Models\Inventory;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Pet;
use App\Models\PetVetAccess;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Locks the dashboard audit fixes: clinic-wide aggregation for `clinic_owner` (P0 — the owner
 * who doesn't see patients personally used to show "0 appointments" with the clinic full),
 * profile segmentation (`professionalContext`), and the counters that used to be dead
 * (`appointmentsTrend`, `clientsTrend`, `totalRecords`, `lowStockItems`, `activeServices`) or
 * simply didn't exist (`overdueInvoices`/`upcomingInvoices`, `activeHospitalizations`,
 * `pendingVetAccessRequests`, `documentVerificationStatus`).
 */
class ProfessionalDashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_clinic_owner_stats_aggregate_the_whole_team(): void
    {
        $owner = $this->createProfessionalUser('clinic_owner', 'clinic');
        $vet = $this->createProfessionalUser('clinic_vet', 'clinic');
        $organization = Organization::factory()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $vet->id,
            'role' => OrganizationMember::ROLE_VETERINARIAN,
        ]);

        $client = User::factory()->tutor()->create();

        // Booked by the employee, under the employee's own user id — never the owner's.
        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'appointment_date' => now()->addHour(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($owner);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.stats.todayAppointments'));
        $this->assertTrue($response->json('data.professionalContext.isClinicAggregate'));
        $this->assertSame('clinic_owner', $response->json('data.professionalContext.role'));
    }

    public function test_clinic_vet_stats_stay_personal(): void
    {
        $owner = $this->createProfessionalUser('clinic_owner', 'clinic');
        $vet = $this->createProfessionalUser('clinic_vet', 'clinic');
        $organization = Organization::factory()->create();

        OrganizationMember::factory()->owner()->create([
            'organization_id' => $organization->id,
            'user_id' => $owner->id,
        ]);
        OrganizationMember::factory()->create([
            'organization_id' => $organization->id,
            'user_id' => $vet->id,
            'role' => OrganizationMember::ROLE_VETERINARIAN,
        ]);

        $client = User::factory()->tutor()->create();

        // Booked by the owner — must never leak into the employee's personal dashboard.
        Appointment::create([
            'professional_id' => $owner->id,
            'client_id' => $client->id,
            'appointment_date' => now()->addHour(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertSame(0, $response->json('data.stats.todayAppointments'));
        $this->assertFalse($response->json('data.professionalContext.isClinicAggregate'));
        $this->assertSame('clinic_vet', $response->json('data.professionalContext.role'));
    }

    public function test_petshop_owner_does_not_get_clinical_only_counters(): void
    {
        $petshopOwner = $this->createProfessionalUser('petshop_owner', 'petshop');

        Sanctum::actingAs($petshopOwner);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertFalse($response->json('data.professionalContext.isClinical'));
        $this->assertSame('petshop', $response->json('data.professionalContext.professionalType'));
        $this->assertNull($response->json('data.stats.totalRecords'));
        $this->assertNull($response->json('data.stats.activeHospitalizations'));
        $this->assertNull($response->json('data.stats.pendingVetAccessRequests'));
    }

    public function test_vet_freelancer_gets_clinical_counters_computed(): void
    {
        $vet = $this->createProfessionalUser('vet_freelancer', 'vet');
        $pet = Pet::factory()->create();

        MedicalRecord::create([
            'pet_id' => $pet->id,
            'professional_id' => $vet->id,
            'record_date' => now()->toDateString(),
        ]);

        Hospitalization::create([
            'pet_id' => $pet->id,
            'professional_id' => $vet->id,
            'admission_date' => now()->toDateString(),
            'reason' => 'Observação',
            'status' => 'active',
        ]);

        PetVetAccess::factoryCreatePending($vet, $pet);

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertTrue($response->json('data.professionalContext.isClinical'));
        $this->assertSame(1, $response->json('data.stats.totalRecords'));
        $this->assertSame(1, $response->json('data.stats.activeHospitalizations'));
        $this->assertSame(1, $response->json('data.stats.pendingVetAccessRequests'));
    }

    public function test_inventory_and_service_counters_are_computed(): void
    {
        $vet = $this->createProfessionalUser('vet_freelancer', 'vet');

        Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Vacina V10',
            'category' => 'vaccine',
            'quantity' => 2,
            'min_quantity' => 5,
        ]);

        Inventory::create([
            'professional_id' => $vet->id,
            'item_name' => 'Seringa',
            'category' => 'supply',
            'quantity' => 100,
            'min_quantity' => 5,
            'expiry_date' => now()->addDays(10)->toDateString(),
        ]);

        Service::create([
            'professional_id' => $vet->id,
            'name' => 'Consulta geral',
            'category' => 'consultation',
            'price' => 150,
            'active' => true,
        ]);

        Service::create([
            'professional_id' => $vet->id,
            'name' => 'Serviço descontinuado',
            'category' => 'other',
            'price' => 50,
            'active' => false,
        ]);

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.stats.lowStockItems'));
        $this->assertSame(1, $response->json('data.stats.expiringSoonItems'));
        $this->assertSame(1, $response->json('data.stats.activeServices'));
    }

    public function test_invoices_split_into_overdue_and_upcoming(): void
    {
        $vet = $this->createProfessionalUser('vet_freelancer', 'vet');
        $client = User::factory()->tutor()->create();

        Invoice::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'invoice_number' => 'INV-OVERDUE',
            'issue_date' => now()->subDays(20)->toDateString(),
            'due_date' => now()->subDays(5)->toDateString(),
            'items' => [['service_id' => 1, 'description' => 'Consulta', 'quantity' => 1, 'price' => 100]],
            'subtotal' => 100,
            'total' => 100,
            'status' => 'pending',
        ]);

        Invoice::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'invoice_number' => 'INV-UPCOMING',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['service_id' => 1, 'description' => 'Consulta', 'quantity' => 1, 'price' => 100]],
            'subtotal' => 100,
            'total' => 100,
            'status' => 'pending',
        ]);

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertSame(1, $response->json('data.stats.overdueInvoices'));
        $this->assertSame(1, $response->json('data.stats.upcomingInvoices'));
        $this->assertSame(2, $response->json('data.stats.pendingInvoices'));
    }

    public function test_document_verification_status_reflects_latest_crmv_document(): void
    {
        $vet = $this->createProfessionalUser('vet_freelancer', 'vet');

        Document::create([
            'user_id' => $vet->id,
            'document_type' => 'crmv',
            'file_name' => 'crmv.pdf',
            'file_path' => 'documents/crmv.pdf',
            'file_type' => 'pdf',
            'file_size' => 1024,
            'original_name' => 'crmv.pdf',
            'verification_status' => 'rejected',
        ]);

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertSame('rejected', $response->json('data.stats.documentVerificationStatus'));
    }

    public function test_document_verification_status_is_null_without_upload(): void
    {
        $vet = $this->createProfessionalUser('vet_freelancer', 'vet');

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertNull($response->json('data.stats.documentVerificationStatus'));
    }

    public function test_appointments_trend_is_null_without_a_comparison_baseline(): void
    {
        $vet = $this->createProfessionalUser('vet_freelancer', 'vet');
        $client = User::factory()->tutor()->create();

        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'appointment_date' => now()->addHour(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertNull($response->json('data.stats.appointmentsTrend'));
    }

    /**
     * Regressão do defeito original que motivou a auditoria: `revenueTrend` respondia `0`
     * (mentindo "sem variação") quando não havia faturamento no mês anterior para comparar,
     * enquanto `appointmentsTrend`/`clientsTrend` já respondiam `null`. Unificado para usar o
     * mesmo `percentTrend()`.
     */
    public function test_revenue_trend_is_null_without_a_comparison_baseline(): void
    {
        $vet = $this->createProfessionalUser('vet_freelancer', 'vet');
        $client = User::factory()->tutor()->create();

        Invoice::create([
            'professional_id' => $vet->id,
            'client_id' => $client->id,
            'invoice_number' => 'INV-THIS-MONTH-ONLY',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['service_id' => 1, 'description' => 'Consulta', 'quantity' => 1, 'price' => 100]],
            'subtotal' => 100,
            'total' => 100,
            'status' => 'paid',
        ]);

        Sanctum::actingAs($vet);
        $response = $this->getJson('/api/professional/dashboard/stats');

        $response->assertOk();
        $this->assertNull($response->json('data.stats.revenueTrend'));
    }

    private function createProfessionalUser(string $role, string $professionalType): User
    {
        $user = User::factory()->create([
            'role' => 'professional',
            'profile_completed' => true,
            'registration_status' => 'approved',
        ]);

        $user->assignRole(Role::findOrCreate($role, 'web'));

        Professional::factory()->create([
            'user_id' => $user->id,
            'professional_type' => $professionalType,
        ]);

        return $user;
    }
}
