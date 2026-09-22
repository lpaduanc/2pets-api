<?php

namespace Database\Seeders;

use App\Enums\InventoryCategory;
use App\Enums\StockMovementType;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Pet;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Stock\LegacyInventoryClassifier;
use App\Services\Stock\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BusinessInsightsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create or get professional user
        $user = User::firstOrCreate(
            ['email' => 'professional@2pets.com'],
            [
                'name' => 'Dr. Carlos Silva',
                'password' => Hash::make('password'),
                'role' => 'professional',
            ]
        );

        // Create professional profile if doesn't exist
        $professional = \App\Models\Professional::firstOrCreate(
            ['user_id' => $user->id],
            [
                'business_name' => 'Clínica Veterinária Dr. Carlos',
                'type' => 'Clínica Veterinária',
            ]
        );

        // Cleanup previous data (both bad data using User ID and good data for fresh start)
        $userId = $user->id;
        $profId = $professional->id;

        // Delete bad data linked to User ID
        Invoice::where('professional_id', $userId)->delete();
        Appointment::where('professional_id', $userId)->delete();
        Product::where('professional_id', $userId)->delete();
        Service::where('professional_id', $userId)->delete();

        // Delete existing data linked to Professional ID (for fresh seed)
        Invoice::where('professional_id', $profId)->delete();
        Appointment::where('professional_id', $profId)->delete();
        Product::where('professional_id', $profId)->delete();
        Service::where('professional_id', $profId)->delete();

        // Create some client users
        $clients = [];
        for ($i = 1; $i <= 50; $i++) {
            $client = User::firstOrCreate(
                ['email' => "client{$i}@example.com"],
                [
                    'name' => "Cliente {$i}",
                    'password' => Hash::make('password'),
                    'role' => 'tutor',
                ]
            );
            $clients[] = $client;

            // Create a pet for each client
            Pet::firstOrCreate(
                ['name' => "Pet do Cliente {$i}", 'user_id' => $client->id],
                [
                    'species' => $i % 2 == 0 ? 'dog' : 'cat',
                    'breed' => $i % 2 == 0 ? 'Labrador' : 'Siamês',
                    'birth_date' => now()->subYears(rand(1, 10)),
                    'weight' => rand(3, 30),
                ]
            );
        }

        // Create Services
        $services = [
            ['name' => 'Consulta Veterinária', 'category' => 'consultation', 'price' => 150.00, 'duration' => 30],
            ['name' => 'Vacinação', 'category' => 'other', 'price' => 80.00, 'duration' => 15],
            ['name' => 'Banho e Tosa', 'category' => 'grooming', 'price' => 120.00, 'duration' => 60],
            ['name' => 'Cirurgia Simples', 'category' => 'surgery', 'price' => 800.00, 'duration' => 120],
            ['name' => 'Exames Laboratoriais', 'category' => 'exam', 'price' => 200.00, 'duration' => 20],
        ];

        foreach ($services as $serviceData) {
            Service::create([
                'professional_id' => $professional->id,
                'name' => $serviceData['name'],
                'category' => $serviceData['category'],
                'price' => $serviceData['price'],
                'duration' => $serviceData['duration'],
                'active' => true,
            ]);
        }

        // Create Appointments for the last 12 months
        $appointmentTypes = ['consultation', 'vaccination', 'surgery', 'checkup', 'emergency', 'grooming', 'exam'];
        $statuses = ['completed', 'completed', 'completed', 'completed', 'scheduled', 'cancelled']; // More completed

        // Generate roughly 1-2 appointments per day for the last year
        for ($i = 0; $i < 400; $i++) {
            $client = $clients[array_rand($clients)];
            $pet = $client->pets->first();

            if (! $pet) {
                continue;
            }

            $daysAgo = rand(0, 365);
            $appointmentDate = now()->subDays($daysAgo);

            // Skip if Sunday (just for realism)
            if ($appointmentDate->dayOfWeek === 0) {
                continue;
            }

            $type = $appointmentTypes[array_rand($appointmentTypes)];
            $status = $daysAgo > 7 ? 'completed' : $statuses[array_rand($statuses)]; // Past appointments mostly completed

            $price = match ($type) {
                'consultation' => 150.00,
                'vaccination' => 80.00,
                'surgery' => 800.00,
                'checkup' => 100.00,
                'emergency' => 250.00,
                'grooming' => 120.00,
                'exam' => 200.00,
            };

            $appointment = Appointment::create([
                'professional_id' => $professional->id,
                'client_id' => $client->id,
                'pet_id' => $pet->id,
                'appointment_date' => $appointmentDate->format('Y-m-d'),
                'appointment_time' => $appointmentDate->format('H:i:s'),
                'duration' => rand(15, 60),
                'type' => $type,
                'status' => $status,
                'reason' => "Atendimento de {$type}",
                'price' => $price,
            ]);

            // Create invoice for completed appointments
            if ($status === 'completed') {
                Invoice::create([
                    'professional_id' => $professional->id,
                    'client_id' => $client->id,
                    'appointment_id' => $appointment->id,
                    'invoice_number' => 'INV-'.str_pad($i + 1000, 5, '0', STR_PAD_LEFT),
                    'issue_date' => $appointmentDate,
                    'items' => [
                        [
                            'description' => ucfirst($type),
                            'quantity' => 1,
                            'price' => $price,
                            'total' => $price,
                        ],
                    ],
                    'subtotal' => $price,
                    'total' => $price,
                    'status' => rand(0, 10) > 1 ? 'paid' : 'pending', // 90% paid, 10% pending
                    'due_date' => $appointmentDate->copy()->addDays(7),
                    'created_at' => $appointmentDate, // Important for monthly revenue queries
                    'updated_at' => $appointmentDate,
                ]);
            }
        }

        // Create Inventory Items
        $inventoryItems = [
            ['item_name' => 'Vacina V10', 'category' => 'vaccine', 'quantity' => 25, 'min_quantity' => 10, 'cost_price' => 45.00, 'selling_price' => 80.00],
            ['item_name' => 'Vacina Antirrábica', 'category' => 'vaccine', 'quantity' => 30, 'min_quantity' => 15, 'cost_price' => 35.00, 'selling_price' => 60.00],
            ['item_name' => 'Ração Premium 15kg', 'category' => 'supply', 'quantity' => 8, 'min_quantity' => 5, 'cost_price' => 120.00, 'selling_price' => 180.00],
            ['item_name' => 'Shampoo Antipulgas', 'category' => 'supply', 'quantity' => 3, 'min_quantity' => 10, 'cost_price' => 25.00, 'selling_price' => 45.00], // Low stock
            ['item_name' => 'Coleira Antipulgas', 'category' => 'supply', 'quantity' => 15, 'min_quantity' => 8, 'cost_price' => 30.00, 'selling_price' => 55.00],
            ['item_name' => 'Antibiótico Genérico', 'category' => 'medication', 'quantity' => 12, 'min_quantity' => 10, 'cost_price' => 18.00, 'selling_price' => 35.00],
            ['item_name' => 'Vermífugo', 'category' => 'medication', 'quantity' => 5, 'min_quantity' => 15, 'cost_price' => 22.00, 'selling_price' => 40.00], // Low stock
            ['item_name' => 'Brinquedo para Cães', 'category' => 'supply', 'quantity' => 20, 'min_quantity' => 10, 'cost_price' => 15.00, 'selling_price' => 30.00],
        ];

        $classifier = new LegacyInventoryClassifier;
        $stock = app(StockService::class);
        $supplierId = Supplier::firstOrCreate(
            ['professional_id' => $user->id, 'organization_id' => null, 'legal_name' => 'Fornecedor Pet Ltda'],
            ['active' => true],
        )->id;

        foreach ($inventoryItems as $item) {
            $category = InventoryCategory::from($item['category']);
            $groupId = ProductGroup::firstOrCreate(
                ['professional_id' => $user->id, 'organization_id' => null, 'name' => $classifier->groupNameFor($category)],
                ['active' => true],
            )->id;

            $product = Product::create([
                'professional_id' => $user->id,
                'product_group_id' => $groupId,
                'last_supplier_id' => $supplierId,
                'name' => $item['item_name'],
                'sku' => 'DEMO-'.Str::slug($item['item_name']),
                'unit_of_sale' => 'UN',
                'purpose' => $classifier->purposeFor($category),
                'price' => $item['selling_price'],
                'average_cost' => $item['cost_price'],
                'last_cost' => $item['cost_price'],
                'controls_stock' => true,
                'track_inventory' => true,
                'min_stock' => $item['min_quantity'],
                'expiry_date' => now()->addMonths(rand(1, 24)),
            ]);

            $stock->in($product, StockMovementType::OPENING_BALANCE, $item['quantity'], [
                'unit_cost' => $item['cost_price'],
                'notes' => 'Saldo inicial do seed de demonstração.',
            ]);
        }

        $this->command->info('✅ Business insights data seeded successfully!');
        $this->command->info('📊 Created:');
        $this->command->info('   - 1 Professional user (professional@2pets.com / password)');
        $this->command->info('   - 50 Client users with pets');
        $this->command->info('   - 5 Services');
        $this->command->info('   - ~350 Appointments (last 12 months)');
        $this->command->info('   - ~300 Invoices');
        $this->command->info('   - 8 Products (estoque)');
    }
}
