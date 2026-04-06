<?php

namespace Database\Seeders;

use App\Models\Breed;
use Illuminate\Database\Seeder;

class BreedSeeder extends Seeder
{
    public function run(): void
    {
        $breeds = [
            // ─── Cães (20 raças populares no Brasil) ───
            ['species' => 'dog', 'name' => 'SRD (Sem Raça Definida)', 'size_category' => 'medium', 'life_expectancy_years' => 14],
            ['species' => 'dog', 'name' => 'Labrador Retriever', 'size_category' => 'large', 'life_expectancy_years' => 12],
            ['species' => 'dog', 'name' => 'Golden Retriever', 'size_category' => 'large', 'life_expectancy_years' => 12],
            ['species' => 'dog', 'name' => 'Bulldog Francês', 'size_category' => 'small', 'life_expectancy_years' => 11],
            ['species' => 'dog', 'name' => 'Poodle', 'size_category' => 'medium', 'life_expectancy_years' => 14],
            ['species' => 'dog', 'name' => 'Pastor Alemão', 'size_category' => 'large', 'life_expectancy_years' => 11],
            ['species' => 'dog', 'name' => 'Shih Tzu', 'size_category' => 'small', 'life_expectancy_years' => 14],
            ['species' => 'dog', 'name' => 'Yorkshire Terrier', 'size_category' => 'mini', 'life_expectancy_years' => 15],
            ['species' => 'dog', 'name' => 'Rottweiler', 'size_category' => 'large', 'life_expectancy_years' => 10],
            ['species' => 'dog', 'name' => 'Pit Bull', 'size_category' => 'medium', 'life_expectancy_years' => 13],
            ['species' => 'dog', 'name' => 'Border Collie', 'size_category' => 'medium', 'life_expectancy_years' => 14],
            ['species' => 'dog', 'name' => 'Pinscher Miniatura', 'size_category' => 'mini', 'life_expectancy_years' => 15],
            ['species' => 'dog', 'name' => 'Maltês', 'size_category' => 'mini', 'life_expectancy_years' => 14],
            ['species' => 'dog', 'name' => 'Dachshund (Salsicha)', 'size_category' => 'small', 'life_expectancy_years' => 14],
            ['species' => 'dog', 'name' => 'Beagle', 'size_category' => 'medium', 'life_expectancy_years' => 13],
            ['species' => 'dog', 'name' => 'Husky Siberiano', 'size_category' => 'large', 'life_expectancy_years' => 13],
            ['species' => 'dog', 'name' => 'Boxer', 'size_category' => 'large', 'life_expectancy_years' => 11],
            ['species' => 'dog', 'name' => 'Lhasa Apso', 'size_category' => 'small', 'life_expectancy_years' => 14],
            ['species' => 'dog', 'name' => 'Cocker Spaniel', 'size_category' => 'medium', 'life_expectancy_years' => 13],
            ['species' => 'dog', 'name' => 'Spitz Alemão (Lulu da Pomerânia)', 'size_category' => 'mini', 'life_expectancy_years' => 15],

            // ─── Gatos (10 raças populares no Brasil) ───
            ['species' => 'cat', 'name' => 'SRD (Sem Raça Definida)', 'size_category' => 'medium', 'life_expectancy_years' => 16],
            ['species' => 'cat', 'name' => 'Persa', 'size_category' => 'medium', 'life_expectancy_years' => 15],
            ['species' => 'cat', 'name' => 'Siamês', 'size_category' => 'medium', 'life_expectancy_years' => 15],
            ['species' => 'cat', 'name' => 'Maine Coon', 'size_category' => 'large', 'life_expectancy_years' => 14],
            ['species' => 'cat', 'name' => 'Ragdoll', 'size_category' => 'large', 'life_expectancy_years' => 15],
            ['species' => 'cat', 'name' => 'British Shorthair', 'size_category' => 'medium', 'life_expectancy_years' => 15],
            ['species' => 'cat', 'name' => 'Bengal', 'size_category' => 'medium', 'life_expectancy_years' => 14],
            ['species' => 'cat', 'name' => 'Scottish Fold', 'size_category' => 'medium', 'life_expectancy_years' => 14],
            ['species' => 'cat', 'name' => 'Angorá', 'size_category' => 'medium', 'life_expectancy_years' => 15],
            ['species' => 'cat', 'name' => 'Sphynx', 'size_category' => 'medium', 'life_expectancy_years' => 14],

            // ─── Aves (6 raças populares) ───
            ['species' => 'bird', 'name' => 'Calopsita', 'size_category' => 'small', 'life_expectancy_years' => 20],
            ['species' => 'bird', 'name' => 'Periquito Australiano', 'size_category' => 'mini', 'life_expectancy_years' => 10],
            ['species' => 'bird', 'name' => 'Papagaio-verdadeiro', 'size_category' => 'medium', 'life_expectancy_years' => 50],
            ['species' => 'bird', 'name' => 'Canario', 'size_category' => 'mini', 'life_expectancy_years' => 12],
            ['species' => 'bird', 'name' => 'Agapornis', 'size_category' => 'mini', 'life_expectancy_years' => 15],
            ['species' => 'bird', 'name' => 'Arara-caninde', 'size_category' => 'large', 'life_expectancy_years' => 60],

            // ─── Repteis (6 raças populares) ───
            ['species' => 'reptile', 'name' => 'Jabuti-piranga', 'size_category' => 'medium', 'life_expectancy_years' => 80],
            ['species' => 'reptile', 'name' => 'Iguana-verde', 'size_category' => 'large', 'life_expectancy_years' => 20],
            ['species' => 'reptile', 'name' => 'Gecko Leopardo', 'size_category' => 'mini', 'life_expectancy_years' => 20],
            ['species' => 'reptile', 'name' => 'Dragao Barbudo', 'size_category' => 'small', 'life_expectancy_years' => 12],
            ['species' => 'reptile', 'name' => 'Jiboia', 'size_category' => 'large', 'life_expectancy_years' => 25],
            ['species' => 'reptile', 'name' => 'Cagado-de-barbicha', 'size_category' => 'small', 'life_expectancy_years' => 30],

            // ─── Roedores e Lagomorfos (7 raças populares) ───
            ['species' => 'rodent', 'name' => 'Hamster Sirio', 'size_category' => 'mini', 'life_expectancy_years' => 3],
            ['species' => 'rodent', 'name' => 'Porquinho-da-India', 'size_category' => 'small', 'life_expectancy_years' => 7],
            ['species' => 'rodent', 'name' => 'Coelho Mini Lop', 'size_category' => 'small', 'life_expectancy_years' => 10],
            ['species' => 'rodent', 'name' => 'Coelho Lion Head', 'size_category' => 'small', 'life_expectancy_years' => 9],
            ['species' => 'rodent', 'name' => 'Coelho Holandes', 'size_category' => 'small', 'life_expectancy_years' => 9],
            ['species' => 'rodent', 'name' => 'Chinchila', 'size_category' => 'small', 'life_expectancy_years' => 15],
            ['species' => 'rodent', 'name' => 'Gerbil', 'size_category' => 'mini', 'life_expectancy_years' => 4],
        ];

        foreach ($breeds as $breed) {
            Breed::firstOrCreate(
                ['species' => $breed['species'], 'name' => $breed['name']],
                $breed
            );
        }
    }
}
