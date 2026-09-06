<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Locks the Fase 8 aggregation rewrite (AdminController, ProfessionalDashboardController,
 * DashboardController, AiBusinessInsightsController, RevenueReportService): every counter
 * collapsed into `COUNT(*) FILTER (WHERE ...)` queries must keep returning the exact same
 * numbers the one-count-per-query version returned.
 *
 * Also locks a bug found while writing these tests: `appointments`/`invoices`/`services`/
 * `inventories`/`reviews`.`professional_id` are FKs to `users.id` (see
 * AppointmentController::store / InvoiceController::store), not to `professionals.id`. The two
 * controllers below used to filter by `$professional->id` (the `professionals` primary key)
 * instead of the authenticated user's id — every fixture here uses the user id on purpose, to
 * pin the corrected behaviour.
 */
class DashboardAggregationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_stats_aggregates_users_in_a_single_query_correctly(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        User::factory()->tutor()->count(2)->create();
        User::factory()->professional()->create();
        User::factory()->company()->create(); // registration_status pending by factory default
        User::factory()->tutor()->suspended()->create();

        $response = $this->getJson('/api/admin/dashboard/stats');

        $response->assertOk();
        $data = $response->json('data');

        // admin (auth user) + 2 tutors + 1 professional + 1 company + 1 suspended tutor = 6
        $this->assertSame(6, $data['totalUsers']);
        $this->assertSame(3, $data['users']['tutors']);
        $this->assertSame(1, $data['users']['professionals']);
        $this->assertSame(1, $data['users']['companies']);
        $this->assertSame(1, $data['users']['admins']);
        $this->assertSame(1, $data['pendingApprovals']);
        $this->assertSame(1, $data['suspended_users']);
        $this->assertSame(6, $data['recent_registrations']);
    }

    /**
     * `generate_series` must report every one of the last 7 days, including the ones with
     * zero registrations — a naive `GROUP BY date(created_at)` would silently drop them.
     */
    public function test_admin_stats_registration_trend_reports_zero_count_days(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard/stats');
        $trend = $response->json('data.registration_trend');

        $this->assertCount(7, $trend);
        $this->assertSame(now()->format('Y-m-d'), $trend[6]['date']);
        $this->assertSame(1, $trend[6]['count'], 'today must count the admin user created in this test');
        $this->assertSame(0, $trend[0]['count'], 'a day with zero registrations must still be present, at 0');
    }

    public function test_professional_stats_aggregates_appointments_and_invoices_correctly(): void
    {
        $professional = User::factory()->professional()->create();
        Professional::factory()->veterinarian()->create(['user_id' => $professional->id]);
        Sanctum::actingAs($professional);

        // `appointments`/`invoices`.professional_id store the *user* id.
        $profId = $professional->id;
        $clientA = User::factory()->tutor()->create();
        $clientB = User::factory()->tutor()->create();

        // Counts toward todayAppointments (status != cancelled).
        Appointment::create([
            'professional_id' => $profId,
            'client_id' => $clientA->id,
            'appointment_date' => now()->addHour(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        // Same day but cancelled — excluded from todayAppointments, still counts for
        // total/new clients (those never filtered by status, before or after the rewrite).
        Appointment::create([
            'professional_id' => $profId,
            'client_id' => $clientA->id,
            'appointment_date' => now()->addHours(2),
            'type' => 'consultation',
            'status' => 'cancelled',
        ]);

        // Different day, pending confirmation.
        Appointment::create([
            'professional_id' => $profId,
            'client_id' => $clientB->id,
            'appointment_date' => now()->addDay(),
            'type' => 'consultation',
            'status' => 'pending',
        ]);

        $this->createInvoice($profId, $clientA->id, 'INV-CURRENT', 100, 'paid', now());
        $this->createInvoice($profId, $clientB->id, 'INV-LASTMONTH', 200, 'paid', now()->subMonth()->startOfMonth()->addDays(5));
        $this->createInvoice($profId, $clientB->id, 'INV-PENDING', 50, 'pending', now());

        $response = $this->getJson('/api/professional/dashboard/stats');
        $response->assertOk();
        $stats = $response->json('data.stats');

        $this->assertSame(1, $stats['todayAppointments']);
        $this->assertSame(1, $stats['pendingConfirmations']);
        $this->assertSame(2, $stats['totalClients']);
        $this->assertSame(2, $stats['newClients']);
        $this->assertEquals(100.0, $stats['monthlyRevenue']);
        $this->assertSame(1, $stats['pendingInvoices']);
        $this->assertSame(-50, $stats['revenueTrend']);
    }

    public function test_tutor_stats_counts_upcoming_and_today_appointments_correctly(): void
    {
        $tutor = User::factory()->tutor()->create();
        Sanctum::actingAs($tutor);

        $vet = User::factory()->professional()->create();
        Professional::factory()->veterinarian()->create(['user_id' => $vet->id]);
        Pet::factory()->create(['user_id' => $tutor->id]);

        // Counts as both upcoming and today.
        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'appointment_date' => now()->addHour(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        // In the past — neither upcoming nor today.
        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'appointment_date' => now()->subDay(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        // Tomorrow — upcoming, but not today.
        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'appointment_date' => now()->addDay(),
            'type' => 'consultation',
            'status' => 'scheduled',
        ]);

        // Today but cancelled — neither.
        Appointment::create([
            'professional_id' => $vet->id,
            'client_id' => $tutor->id,
            'appointment_date' => now()->addHours(3),
            'type' => 'consultation',
            'status' => 'cancelled',
        ]);

        $response = $this->getJson('/api/dashboard/stats');
        $response->assertOk();
        $stats = $response->json('data.stats');

        $this->assertSame(2, $stats['upcomingAppointments']);
        $this->assertSame(1, $stats['todayAppointments']);
        $this->assertSame(1, $stats['totalPets']);
    }

    /**
     * Regression test for the fixed bug: `whereMonth('created_at', $now->month)` compared only
     * the month number and ignored the year, so an invoice from the same month last year used
     * to leak into "current month" revenue.
     */
    public function test_ai_insights_current_month_revenue_excludes_same_month_last_year(): void
    {
        Config::set('features.ai_business', true);
        Http::fake(['*' => Http::response(['candidates' => [
            ['content' => ['parts' => [['text' => 'insight']]]],
        ]], 200)]);

        $professional = User::factory()->professional()->create();
        Professional::factory()->veterinarian()->create(['user_id' => $professional->id]);
        Sanctum::actingAs($professional);

        $profId = $professional->id;
        $client = User::factory()->tutor()->create();

        $this->createInvoice($profId, $client->id, 'INV-THIS-YEAR', 100, 'paid', now());
        $this->createInvoice($profId, $client->id, 'INV-LAST-YEAR-SAME-MONTH', 500, 'paid', now()->subYear());

        $response = $this->getJson('/api/professional/ai/insights');

        $response->assertOk();
        $this->assertEquals(100.0, $response->json('metrics.revenue.currentMonth'));
    }

    public function test_revenue_report_aggregates_paid_invoices_in_sql(): void
    {
        $professional = User::factory()->professional()->create();
        Professional::factory()->veterinarian()->create(['user_id' => $professional->id]);
        Sanctum::actingAs($professional);

        $client = User::factory()->tutor()->create();

        Invoice::create([
            'professional_id' => $professional->id,
            'client_id' => $client->id,
            'invoice_number' => 'INV-REPORT-PAID',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['service_id' => 1, 'description' => 'Consulta Geral', 'quantity' => 1, 'price' => 150]],
            'subtotal' => 150,
            'total' => 150,
            'status' => 'paid',
            'payment_date' => now()->toDateString(),
        ]);

        Invoice::create([
            'professional_id' => $professional->id,
            'client_id' => $client->id,
            'invoice_number' => 'INV-REPORT-PENDING',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(10)->toDateString(),
            'items' => [['service_id' => 1, 'description' => 'Consulta Geral', 'quantity' => 1, 'price' => 999]],
            'subtotal' => 999,
            'total' => 999,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/reports/revenue?'.http_build_query([
            'start_date' => now()->subDays(30)->toDateString(),
            'end_date' => now()->toDateString(),
        ]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(150.0, $data['summary']['total_revenue']);
        $this->assertSame(1, $data['summary']['total_invoices']);
        $this->assertSame(1, $data['summary']['unique_clients']);
        $this->assertSame('Consulta Geral', $data['by_service'][0]['name']);
        $this->assertEquals(150.0, $data['by_service'][0]['revenue']);
        $this->assertCount(1, $data['by_month']);
        $this->assertEquals(150.0, $data['by_month'][0]['revenue']);
        $this->assertCount(1, $data['invoices']);
        $this->assertSame('INV-REPORT-PAID', $data['invoices'][0]['invoice_number']);
    }

    /**
     * `Invoice::create()` cannot set `created_at` directly (not fillable, and Eloquent
     * would stamp "now" over it anyway) — write it, then backdate with a raw update.
     */
    private function createInvoice(int $professionalId, int $clientId, string $number, float $total, string $status, \Carbon\Carbon $createdAt): Invoice
    {
        $invoice = Invoice::create([
            'professional_id' => $professionalId,
            'client_id' => $clientId,
            'invoice_number' => $number,
            'issue_date' => $createdAt->toDateString(),
            'due_date' => $createdAt->copy()->addDays(10)->toDateString(),
            'items' => [['service_id' => 1, 'description' => 'Consulta', 'quantity' => 1, 'price' => $total]],
            'subtotal' => $total,
            'total' => $total,
            'status' => $status,
        ]);

        DB::table('invoices')->where('id', $invoice->id)->update(['created_at' => $createdAt]);

        return $invoice->fresh();
    }
}
