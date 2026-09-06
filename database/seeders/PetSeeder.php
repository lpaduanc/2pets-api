<?php

namespace Database\Seeders;

use App\Models\Pet;
use App\Models\PetDeworming;
use App\Models\PetMedication;
use App\Models\PetWeightHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PetSeeder extends Seeder
{
    public function run(): void
    {
        $tutor = User::updateOrCreate(
            ['email' => 'tutor@2pets.com'],
            [
                'name' => 'Maria Silva',
                'password' => Hash::make('password'),
                'role' => 'tutor',
                'phone' => '(11) 98765-4321',
                'address' => 'Rua das Flores, 123',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01234-567',
            ]
        );

        $this->seedBella($tutor->id);
        $this->seedMax($tutor->id);
        $this->seedLuna($tutor->id);
    }

    private function seedBella(int $userId): void
    {
        $bella = Pet::updateOrCreate(
            ['user_id' => $userId, 'name' => 'Bella'],
            [
                'species' => 'dog',
                'breed' => 'Golden Retriever',
                'birth_date' => '2022-03-15',
                'gender' => 'female',
                'weight' => 28.0,
                'size' => 'large',
                'color' => 'Dourado',
                'coat_colors' => ['golden'],
                'neutered' => true,
                'neutered_status' => 'yes',
                'microchip_number' => 'BR-9281746350',
                'blood_type' => 'DEA 1.1+',
                'allergies' => ['Frango'],
                'food_types' => ['dry_food', 'natural'],
                'food_brand' => 'Royal Canin Golden Retriever',
                'dietary_restrictions' => ['grain_free'],
                'food_allergies' => ['chicken'],
                'food_allergies_other' => null,
                'chronic_diseases' => ['Dermatite Atópica'],
                'chronic_conditions' => ['atopic_dermatitis'],
                'surgeries' => [],
                'previous_hospitalizations' => false,
                'current_medications' => ['Simparic (antipulgas)'],
                'temperament' => ['playful', 'energetic'],
                'behavior_notes' => 'Muito amigável e adora brincar com outros cães',
                'social_with' => ['children', 'dogs'],
                'does_exercise' => 'yes',
                'exercise_types' => ['walking', 'swimming'],
                'exercise_frequency' => 'daily',
                'daily_walk' => true,
                'docile_with_strangers' => 'yes',
                'docile_with_animals' => 'yes',
                'notes' => 'Adora nadar e brincar de buscar',
                'image_url' => 'https://images.unsplash.com/photo-1633722715463-d30f4f325e24?w=400&h=300&fit=crop',
            ]
        );

        $this->syncDewormings($bella->id, [
            ['product_name' => 'Drontal Plus', 'applied_date' => Carbon::today()->subMonths(3), 'next_date' => Carbon::today()->addDays(7)],
            ['product_name' => 'Endal Plus', 'applied_date' => Carbon::today()->subMonths(6), 'next_date' => Carbon::today()->subMonths(3)],
        ]);

        $this->syncMedications($bella->id, [
            ['name' => 'Apoquel', 'dosage' => '16mg', 'frequency' => '1x ao dia', 'start_date' => Carbon::today()->subMonths(2)],
        ]);

        $this->syncWeightHistory($bella->id, [
            ['weight' => 22.0, 'measured_at' => Carbon::today()->subYear()],
            ['weight' => 25.5, 'measured_at' => Carbon::today()->subMonths(6)],
            ['weight' => 28.0, 'measured_at' => Carbon::today()->subMonth()],
        ]);
    }

    private function seedMax(int $userId): void
    {
        $max = Pet::updateOrCreate(
            ['user_id' => $userId, 'name' => 'Max'],
            [
                'species' => 'cat',
                'breed' => 'Siamês',
                'birth_date' => '2023-08-10',
                'gender' => 'male',
                'weight' => 4.5,
                'size' => 'small',
                'color' => 'Branco e Marrom',
                'coat_colors' => ['white', 'brown'],
                'neutered' => true,
                'neutered_status' => 'yes',
                'microchip_number' => 'BR-4457821093',
                'blood_type' => 'A',
                'allergies' => [],
                'food_types' => ['dry_food'],
                'food_brand' => 'Royal Canin Feline',
                'dietary_restrictions' => [],
                'food_allergies' => [],
                'chronic_diseases' => [],
                'chronic_conditions' => [],
                'surgeries' => [],
                'previous_hospitalizations' => true,
                'current_medications' => [],
                'temperament' => ['calm', 'shy'],
                'behavior_notes' => 'Gosta de lugares altos e é muito independente',
                'social_with' => ['strangers'],
                'does_exercise' => 'yes',
                'exercise_types' => ['play'],
                'exercise_frequency' => '2_3x_week',
                'daily_walk' => false,
                'docile_with_strangers' => 'depends',
                'docile_with_animals' => 'depends',
                'notes' => 'Prefere ambientes tranquilos',
                'image_url' => 'https://images.unsplash.com/photo-1574158622682-e40e69881006?w=400&h=300&fit=crop',
            ]
        );

        $this->syncDewormings($max->id, [
            ['product_name' => 'Milbemax', 'applied_date' => Carbon::today()->subMonths(2), 'next_date' => Carbon::today()->addMonth()],
        ]);

        $this->syncWeightHistory($max->id, [
            ['weight' => 3.8, 'measured_at' => Carbon::today()->subMonths(6)],
            ['weight' => 4.5, 'measured_at' => Carbon::today()->subWeeks(2)],
        ]);
    }

    private function seedLuna(int $userId): void
    {
        $luna = Pet::updateOrCreate(
            ['user_id' => $userId, 'name' => 'Luna'],
            [
                'species' => 'dog',
                'breed' => 'Poodle',
                'birth_date' => '2024-01-20',
                'gender' => 'female',
                'weight' => 5.2,
                'size' => 'small',
                'color' => 'Branco',
                'coat_colors' => ['white'],
                'neutered' => false,
                'neutered_status' => 'no',
                'microchip_number' => null,
                'blood_type' => null,
                'allergies' => [],
                'food_types' => ['dry_food', 'wet_food'],
                'food_brand' => 'Premier Pet Small Breed',
                'dietary_restrictions' => [],
                'food_allergies' => [],
                'chronic_diseases' => [],
                'chronic_conditions' => [],
                'surgeries' => [],
                'previous_hospitalizations' => false,
                'current_medications' => [],
                'temperament' => ['playful', 'energetic'],
                'behavior_notes' => 'Muito ativa e curiosa',
                'social_with' => ['children', 'dogs', 'cats'],
                'does_exercise' => 'yes',
                'exercise_types' => ['walking', 'play'],
                'exercise_frequency' => 'daily',
                'daily_walk' => true,
                'docile_with_strangers' => 'yes',
                'docile_with_animals' => 'yes',
                'notes' => 'Adora aprender truques novos',
                'image_url' => 'https://images.unsplash.com/photo-1537151608828-ea2b11777ee8?w=400&h=300&fit=crop',
            ]
        );

        $this->syncWeightHistory($luna->id, [
            ['weight' => 2.1, 'measured_at' => Carbon::today()->subMonths(6)],
            ['weight' => 4.0, 'measured_at' => Carbon::today()->subMonths(3)],
            ['weight' => 5.2, 'measured_at' => Carbon::today()->subDays(10)],
        ]);
    }

    private function syncDewormings(int $petId, array $rows): void
    {
        PetDeworming::where('pet_id', $petId)->delete();
        foreach ($rows as $row) {
            PetDeworming::create(array_merge(['pet_id' => $petId], $row));
        }
    }

    private function syncMedications(int $petId, array $rows): void
    {
        PetMedication::where('pet_id', $petId)->delete();
        foreach ($rows as $row) {
            PetMedication::create(array_merge([
                'pet_id' => $petId,
                'active' => true,
            ], $row));
        }
    }

    private function syncWeightHistory(int $petId, array $rows): void
    {
        PetWeightHistory::where('pet_id', $petId)->delete();
        foreach ($rows as $row) {
            PetWeightHistory::create(array_merge(['pet_id' => $petId], $row));
        }
    }
}
