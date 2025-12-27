<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Pet;
use Illuminate\Support\Facades\Hash;

class PetSeeder extends Seeder
{
    public function run(): void
    {
        // Create a test tutor user
        $tutor = User::firstOrCreate(
            ['email' => 'tutor@2pets.com'],
            [
                'name' => 'Maria Silva',
                'password' => Hash::make('password'),
                'role' => 'tutor',
                'phone' => '(11) 98765-4321',
                'address' => 'Rua das Flores, 123',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01234-567'
            ]
        );

        // Create pets for the tutor
        Pet::firstOrCreate(
            ['user_id' => $tutor->id, 'name' => 'Bella'],
            [
                'species' => 'dog',
                'breed' => 'Golden Retriever',
                'birth_date' => '2022-03-15',
                'gender' => 'female',
                'weight' => 28.0,
                'color' => 'Dourado',
                'neutered' => true,
                'blood_type' => 'DEA 1.1+',
                'allergies' => 'Frango',
                'chronic_diseases' => null,
                'current_medications' => 'Simparic (antipulgas)',
                'temperament' => ['playful', 'energetic'],
                'behavior_notes' => 'Muito amigável e adora brincar com outros cães',
                'social_with' => ['children', 'dogs'],
                'notes' => 'Adora nadar e brincar de buscar',
                'image_url' => 'https://images.unsplash.com/photo-1633722715463-d30f4f325e24?w=400&h=300&fit=crop'
            ]
        );

        Pet::firstOrCreate(
            ['user_id' => $tutor->id, 'name' => 'Max'],
            [
                'species' => 'cat',
                'breed' => 'Siamês',
                'birth_date' => '2023-08-10',
                'gender' => 'male',
                'weight' => 4.5,
                'color' => 'Branco e Marrom',
                'neutered' => true,
                'blood_type' => 'A',
                'allergies' => null,
                'chronic_diseases' => null,
                'current_medications' => null,
                'temperament' => ['calm', 'shy'],
                'behavior_notes' => 'Gosta de lugares altos e é muito independente',
                'social_with' => ['strangers'],
                'notes' => 'Prefere ambientes tranquilos',
                'image_url' => 'https://images.unsplash.com/photo-1574158622682-e40e69881006?w=400&h=300&fit=crop'
            ]
        );

        Pet::firstOrCreate(
            ['user_id' => $tutor->id, 'name' => 'Luna'],
            [
                'species' => 'dog',
                'breed' => 'Poodle',
                'birth_date' => '2024-01-20',
                'gender' => 'female',
                'weight' => 5.2,
                'color' => 'Branco',
                'neutered' => false,
                'blood_type' => null,
                'allergies' => null,
                'chronic_diseases' => null,
                'current_medications' => null,
                'temperament' => ['playful', 'energetic'],
                'behavior_notes' => 'Muito ativa e curiosa',
                'social_with' => ['children', 'dogs', 'cats'],
                'notes' => 'Adora aprender truques novos',
                'image_url' => 'https://images.unsplash.com/photo-1537151608828-ea2b11777ee8?w=400&h=300&fit=crop'
            ]
        );
    }
}
