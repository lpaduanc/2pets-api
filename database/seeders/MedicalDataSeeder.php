<?php

namespace Database\Seeders;

use App\Enums\ProductPurpose;
use App\Enums\StockMovementType;
use App\Models\Appointment;
use App\Models\Hospitalization;
use App\Models\HospitalizationProgressNote;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\Pet;
use App\Models\Prescription;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\Surgery;
use App\Models\User;
use App\Models\Vaccination;
use App\Services\Stock\StockService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MedicalDataSeeder extends Seeder
{
    public function run(): void
    {
        // Get existing users and pets
        $professional = User::where('email', 'vet@2pets.com')->first();
        $tutor = User::where('email', 'tutor@2pets.com')->first();
        $bella = Pet::where('name', 'Bella')->first();
        $max = Pet::where('name', 'Max')->first();

        if (! $professional || ! $tutor || ! $bella || ! $max) {
            $this->command->error('Required users or pets not found. Run PetSeeder and ProfessionalSeeder first.');

            return;
        }

        // Create Appointments
        $appointment1 = Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $bella->id,
            'appointment_date' => Carbon::today(),
            'appointment_time' => '10:00',
            'duration' => 30,
            'type' => 'consultation',
            'status' => 'completed',
            'reason' => 'Consulta de rotina',
            'notes' => 'Pet apresentou bom estado geral',
            'price' => 150.00,
        ]);

        $appointment2 = Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $max->id,
            'appointment_date' => Carbon::today()->addDays(2),
            'appointment_time' => '14:00',
            'duration' => 30,
            'type' => 'vaccination',
            'status' => 'scheduled',
            'reason' => 'Vacinação anual',
            'price' => 80.00,
        ]);

        Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $bella->id,
            'appointment_date' => Carbon::today()->addDays(7),
            'appointment_time' => '11:00',
            'duration' => 45,
            // `checkup` saiu do domínio de `type` na migration
            // `2026_09_29_100000_merge_appointments_type_into_service_category`, que
            // alinhou a coluna às categorias de serviço e converteu os registros
            // legados de `checkup` para `consultation` (a informação de rotina passou
            // a morar em `medical_records.chief_complaint = routine_checkup`).
            'type' => 'consultation',
            'status' => 'confirmed',
            'reason' => 'Retorno - verificar peso',
            'price' => 100.00,
        ]);

        // Create Medical Records
        MedicalRecord::create([
            'pet_id' => $bella->id,
            'professional_id' => $professional->id,
            'appointment_id' => $appointment1->id,
            'record_date' => Carbon::today(),
            'weight' => 28.5,
            'temperature' => 38.5,
            'heart_rate' => 90,
            'respiratory_rate' => 25,
            'subjective' => 'Tutora relata que a Bella está comendo bem e muito ativa. Sem queixas.',
            'objective' => 'Animal alerta, hidratado, mucosas rosadas. Pelagem brilhante. Ausculta cardíaca e pulmonar normais.',
            'assessment' => 'Animal saudável, dentro dos parâmetros normais para a raça e idade.',
            'plan' => 'Manter alimentação atual. Retornar em 6 meses para check-up de rotina.',
            'symptoms' => [],
            'diagnosis' => 'Saudável',
            'treatment_plan' => 'Manutenção preventiva',
            'notes' => 'Próxima vacinação em 3 meses',
        ]);

        MedicalRecord::create([
            'pet_id' => $max->id,
            'professional_id' => $professional->id,
            'record_date' => Carbon::today()->subDays(30),
            'weight' => 4.5,
            'temperature' => 38.8,
            'heart_rate' => 180,
            'respiratory_rate' => 30,
            'subjective' => 'Tutor relata que Max está mais quieto que o normal, comendo menos.',
            'objective' => 'Gato alerta mas apático. Mucosas levemente pálidas. Desidratação leve.',
            'assessment' => 'Possível gastroenterite. Desidratação leve.',
            'plan' => 'Fluidoterapia SC. Antibiótico. Retornar em 3 dias.',
            'symptoms' => ['apatia', 'anorexia', 'desidratação'],
            'diagnosis' => 'Gastroenterite',
            'treatment_plan' => 'Fluidoterapia + Antibioticoterapia por 7 dias',
            'notes' => 'Orientado jejum de 12h e dieta leve',
        ]);

        // Create Vaccinations
        Vaccination::create([
            'pet_id' => $bella->id,
            'professional_id' => $professional->id,
            'vaccine_name' => 'V10 (Polivalente)',
            'manufacturer' => 'Zoetis',
            'batch_number' => 'V10-2024-ABC123',
            'application_date' => Carbon::today()->subMonths(11),
            'next_dose_date' => Carbon::today()->addMonth(),
            'dose_number' => 1,
            'notes' => 'Reforço anual',
        ]);

        Vaccination::create([
            'pet_id' => $bella->id,
            'professional_id' => $professional->id,
            'vaccine_name' => 'Antirrábica',
            'manufacturer' => 'Biovet',
            'batch_number' => 'RAB-2024-XYZ789',
            'application_date' => Carbon::today()->subMonths(11),
            'next_dose_date' => Carbon::today()->addMonth(),
            'dose_number' => 1,
            'notes' => 'Dose anual obrigatória',
        ]);

        Vaccination::create([
            'pet_id' => $max->id,
            'professional_id' => $professional->id,
            'vaccine_name' => 'V4 Felina',
            'manufacturer' => 'Zoetis',
            'batch_number' => 'V4F-2024-DEF456',
            'application_date' => Carbon::today()->subMonths(10),
            'next_dose_date' => Carbon::today()->addMonths(2),
            'dose_number' => 1,
        ]);

        // Create Prescriptions — `items` é `hasMany(PrescriptionItem)`, não mais coluna JSON
        // (contrato docs/atendimento-veterinario/03-contrato-receituario.md §2).
        $prescription1 = Prescription::create([
            'pet_id' => $max->id,
            'professional_id' => $professional->id,
            'prescription_date' => Carbon::today()->subDays(30),
            'valid_until' => Carbon::today()->addDays(30),
            'general_instructions' => 'Manter medicação em temperatura ambiente. Observar sinais de melhora em 48h.',
            'warnings' => 'Se não houver melhora em 48h ou piorar, retornar imediatamente.',
            'is_controlled' => false,
        ]);
        $prescription1->items()->createMany([
            [
                'position' => 1,
                'commercial_name' => 'Amoxicilina + Clavulanato',
                'dose_value' => 250,
                'dose_unit' => 'mg',
                'route' => 'oral',
                'frequency' => 'bid',
                'duration_text' => '7 dias',
                'instructions_for_tutor' => 'Administrar com alimento. Completar todo o tratamento.',
            ],
            [
                'position' => 2,
                'commercial_name' => 'Metoclopramida',
                'dose_value' => 5,
                'dose_unit' => 'mg',
                'route' => 'oral',
                'frequency' => 'bid',
                'duration_text' => '3 dias',
                'instructions_for_tutor' => 'Administrar 30 minutos antes das refeições.',
            ],
        ]);

        $prescription2 = Prescription::create([
            'pet_id' => $bella->id,
            'professional_id' => $professional->id,
            'appointment_id' => $appointment1->id,
            'prescription_date' => Carbon::today(),
            'valid_until' => Carbon::today()->addMonths(3),
            'general_instructions' => 'Antipulgas de uso mensal. Administrar sempre no mesmo dia do mês.',
            'is_controlled' => false,
        ]);
        $prescription2->items()->create([
            'position' => 1,
            'commercial_name' => 'Simparic (Sarolaner)',
            'dose_value' => 40,
            'dose_unit' => 'mg',
            'pharmaceutical_form' => 'chewable',
            'route' => 'oral',
            'frequency' => 'other',
            'frequency_notes' => '1x ao mês',
            'duration_text' => '3 meses',
            'instructions_for_tutor' => 'Administrar 1 comprimido por mês para controle de pulgas e carrapatos.',
        ]);

        // Create Services
        $consultation = Service::create([
            'professional_id' => $professional->id,
            'name' => 'Consulta Geral',
            'description' => 'Avaliação clínica completa',
            'category' => 'consultation',
            'duration' => 30,
            'price' => 150.00,
            'active' => true,
        ]);

        $surgeryService = Service::create([
            'professional_id' => $professional->id,
            'name' => 'Castração Felina (Macho)',
            'description' => 'Orquiectomia bilateral',
            'category' => 'surgery',
            'duration' => 60,
            'price' => 450.00,
            'active' => true,
        ]);

        // Create Products (estoque consolidado — docs/gap-simplesvet/specs/
        // produtos-estoque-consolidado-spec.md)
        $medicationGroupId = ProductGroup::firstOrCreate(
            ['professional_id' => $professional->id, 'organization_id' => null, 'name' => 'Medicamentos'],
            ['active' => true],
        )->id;
        $vaccineGroupId = ProductGroup::firstOrCreate(
            ['professional_id' => $professional->id, 'organization_id' => null, 'name' => 'Vacinas'],
            ['active' => true],
        )->id;
        $vetPharmaId = Supplier::firstOrCreate(
            ['professional_id' => $professional->id, 'organization_id' => null, 'legal_name' => 'VetPharma Distribuidora'],
            ['active' => true],
        )->id;
        $zoetisId = Supplier::firstOrCreate(
            ['professional_id' => $professional->id, 'organization_id' => null, 'legal_name' => 'Zoetis'],
            ['active' => true],
        )->id;
        $stock = app(StockService::class);

        $amoxicillin = Product::create([
            'professional_id' => $professional->id,
            'product_group_id' => $medicationGroupId,
            'last_supplier_id' => $vetPharmaId,
            'name' => 'Amoxicilina 250mg',
            'sku' => 'DEMO-AMOXICILINA-250MG',
            'unit_of_sale' => 'CP',
            'purpose' => ProductPurpose::CONSUMABLE,
            'price' => 1.50,
            'average_cost' => 0.50,
            'last_cost' => 0.50,
            'controls_stock' => true,
            'track_inventory' => true,
            'min_stock' => 20,
            'expiry_date' => Carbon::today()->addYear(),
        ]);
        $stock->in($amoxicillin, StockMovementType::OPENING_BALANCE, 50, ['unit_cost' => 0.50]);

        $vaccineV10 = Product::create([
            'professional_id' => $professional->id,
            'product_group_id' => $vaccineGroupId,
            'last_supplier_id' => $zoetisId,
            'name' => 'Vacina V10',
            'sku' => 'DEMO-VACINA-V10',
            'unit_of_sale' => 'DS',
            'purpose' => ProductPurpose::CONSUMABLE,
            'price' => 90.00,
            'average_cost' => 45.00,
            'last_cost' => 45.00,
            'controls_stock' => true,
            'track_inventory' => true,
            'min_stock' => 5,
            'expiry_date' => Carbon::today()->addMonths(6),
        ]);
        $stock->in($vaccineV10, StockMovementType::OPENING_BALANCE, 10, ['unit_cost' => 45.00]);

        // Create Hospitalizations
        //
        // `daily_notes` e `total_cost` saíram do `$fillable` de `Hospitalization`
        // (docs 11 §4 e 12 §2): a evolução diária virou `HospitalizationProgressNote`
        // (append-only, com autor e hora) e o valor cobrado vem sempre de
        // `Invoice.total`. Como `db:seed` roda sob `Model::unguard()`, passá-los aqui
        // não era ignorado — a `daily_notes` (json sem cast) estourava o seed inteiro
        // com "Array to string conversion".
        //
        // Desde a migration `2026_09_30_100000_add_appointment_id_to_hospitalizations_table`
        // (doc 11 §1) toda internação pendura num `Appointment` com
        // `type = hospitalization` — é por ele que passam faturamento e status.
        $hospitalizationAppointment = Appointment::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'pet_id' => $max->id,
            'appointment_date' => Carbon::today()->subDays(5),
            'appointment_time' => '08:00',
            'duration' => 60,
            'type' => 'hospitalization',
            'status' => 'completed',
            'reason' => 'Internação - gastroenterite severa',
            'price' => 850.00,
        ]);

        $hospitalization = Hospitalization::create([
            'pet_id' => $max->id,
            'professional_id' => $professional->id,
            'appointment_id' => $hospitalizationAppointment->id,
            'admission_date' => Carbon::today()->subDays(5),
            'discharge_date' => Carbon::today()->subDays(2),
            'reason' => 'Gastroenterite severa - desidratação',
            'status' => 'discharged',
            'medications' => [
                ['name' => 'Cerenia', 'dose' => '0.4ml', 'route' => 'SC'],
                ['name' => 'Metadona', 'dose' => '0.2ml', 'route' => 'IM'],
            ],
        ]);

        foreach ([
            [5, 'Admissão. Acesso venoso. Início fluidoterapia.'],
            [4, 'Animal mais alerta. Comeu um pouco de ração úmida.'],
            [3, 'Sem vômitos. Fezes pastosas.'],
        ] as [$daysAgo, $body]) {
            HospitalizationProgressNote::create([
                'hospitalization_id' => $hospitalization->id,
                'author_id' => $professional->id,
                'recorded_at' => Carbon::today()->subDays($daysAgo)->setTime(9, 0),
                'body' => $body,
            ]);
        }

        // Create Surgeries
        Surgery::create([
            'pet_id' => $bella->id,
            'professional_id' => $professional->id,
            'surgery_date' => Carbon::today()->addDays(10),
            'surgery_type' => 'Ovariohisterectomia (Castração)',
            'status' => 'scheduled',
            'pre_op_notes' => 'Jejum alimentar de 12h e hídrico de 4h. Trazer exames pré-operatórios.',
            'procedure_description' => null,
            'post_op_notes' => null,
            'anesthesia_used' => null,
            'complications' => null,
        ]);

        // Create Invoices
        Invoice::create([
            'professional_id' => $professional->id,
            'client_id' => $tutor->id,
            'appointment_id' => $appointment1->id,
            'invoice_number' => 'INV-'.strtoupper(Str::random(8)),
            'issue_date' => Carbon::today(),
            'due_date' => Carbon::today()->addDays(7),
            'items' => [
                ['service_id' => $consultation->id, 'description' => 'Consulta Geral', 'quantity' => 1, 'price' => 150.00],
            ],
            'subtotal' => 150.00,
            'discount' => 0.00,
            'tax' => 0.00,
            'total' => 150.00,
            'status' => 'pending',
            'payment_method' => null,
            'payment_date' => null,
            'notes' => 'Pagamento via PIX ou Cartão',
        ]);

        $this->command->info('Medical data seeded successfully!');
        $this->command->info('- 4 Appointments created');
        $this->command->info('- 2 Medical Records created');
        $this->command->info('- 3 Vaccinations created');
        $this->command->info('- 2 Prescriptions created');
        $this->command->info('- 2 Services created');
        $this->command->info('- 2 Products (estoque) created');
        $this->command->info('- 1 Hospitalization created');
        $this->command->info('- 1 Surgery created');
        $this->command->info('- 1 Invoice created');
    }
}
