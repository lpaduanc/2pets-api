<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Pet;
use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Vaccination;
use App\Models\Prescription;
use App\Models\Hospitalization;
use App\Models\Surgery;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Inventory;
use Carbon\Carbon;
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

        if (!$professional || !$tutor || !$bella || !$max) {
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
            'type' => 'checkup',
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
            'symptoms' => json_encode([]),
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
            'symptoms' => json_encode(['apatia', 'anorexia', 'desidratação']),
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

        // Create Prescriptions
        Prescription::create([
            'pet_id' => $max->id,
            'professional_id' => $professional->id,
            'prescription_date' => Carbon::today()->subDays(30),
            'valid_until' => Carbon::today()->addDays(30),
            'medications' => json_encode([
                [
                    'name' => 'Amoxicilina + Clavulanato',
                    'dosage' => '250mg',
                    'frequency' => '2x ao dia (12/12h)',
                    'duration' => '7 dias',
                    'instructions' => 'Administrar com alimento. Completar todo o tratamento.',
                ],
                [
                    'name' => 'Metoclopramida',
                    'dosage' => '5mg',
                    'frequency' => '2x ao dia (12/12h)',
                    'duration' => '3 dias',
                    'instructions' => 'Administrar 30 minutos antes das refeições.',
                ],
            ]),
            'general_instructions' => 'Manter medicação em temperatura ambiente. Observar sinais de melhora em 48h.',
            'warnings' => 'Se não houver melhora em 48h ou piorar, retornar imediatamente.',
            'is_controlled' => false,
        ]);

        Prescription::create([
            'pet_id' => $bella->id,
            'professional_id' => $professional->id,
            'appointment_id' => $appointment1->id,
            'prescription_date' => Carbon::today(),
            'valid_until' => Carbon::today()->addMonths(3),
            'medications' => json_encode([
                [
                    'name' => 'Simparic (Sarolaner)',
                    'dosage' => '40mg',
                    'frequency' => '1x ao mês',
                    'duration' => '3 meses',
                    'instructions' => 'Administrar 1 comprimido por mês para controle de pulgas e carrapatos.',
                ],
            ]),
            'general_instructions' => 'Antipulgas de uso mensal. Administrar sempre no mesmo dia do mês.',
            'is_controlled' => false,
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

        // Create Inventory
        $amoxicillin = Inventory::create([
            'professional_id' => $professional->id,
            'item_name' => 'Amoxicilina 250mg',
            'category' => 'medication',
            'quantity' => 50,
            'unit' => 'comprimidos',
            'min_quantity' => 20,
            'cost_price' => 0.50,
            'selling_price' => 1.50,
            'supplier' => 'VetPharma Distribuidora',
            'expiry_date' => Carbon::today()->addYear(),
        ]);

        $vaccineV10 = Inventory::create([
            'professional_id' => $professional->id,
            'item_name' => 'Vacina V10',
            'category' => 'vaccine',
            'quantity' => 10,
            'unit' => 'doses',
            'min_quantity' => 5,
            'cost_price' => 45.00,
            'selling_price' => 90.00,
            'supplier' => 'Zoetis',
            'expiry_date' => Carbon::today()->addMonths(6),
        ]);

        // Create Hospitalizations
        Hospitalization::create([
            'pet_id' => $max->id,
            'professional_id' => $professional->id,
            'admission_date' => Carbon::today()->subDays(5),
            'discharge_date' => Carbon::today()->subDays(2),
            'reason' => 'Gastroenterite severa - desidratação',
            'status' => 'discharged',
            'daily_notes' => [
                ['date' => Carbon::today()->subDays(5)->toDateString(), 'note' => 'Admissão. Acesso venoso. Início fluidoterapia.'],
                ['date' => Carbon::today()->subDays(4)->toDateString(), 'note' => 'Animal mais alerta. Comeu um pouco de ração úmida.'],
                ['date' => Carbon::today()->subDays(3)->toDateString(), 'note' => 'Sem vômitos. Fezes pastosas.'],
            ],
            'medications' => [
                ['name' => 'Cerenia', 'dose' => '0.4ml', 'route' => 'SC'],
                ['name' => 'Metadona', 'dose' => '0.2ml', 'route' => 'IM'],
            ],
            'total_cost' => 850.00,
        ]);

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
            'invoice_number' => 'INV-' . strtoupper(Str::random(8)),
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
        $this->command->info('- 3 Appointments created');
        $this->command->info('- 2 Medical Records created');
        $this->command->info('- 3 Vaccinations created');
        $this->command->info('- 2 Prescriptions created');
        $this->command->info('- 2 Services created');
        $this->command->info('- 2 Inventory items created');
        $this->command->info('- 1 Hospitalization created');
        $this->command->info('- 1 Surgery created');
        $this->command->info('- 1 Invoice created');
    }
}
