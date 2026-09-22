<?php

namespace App\Services\Import;

use App\Models\Pet;

/**
 * Cria o pet importado (item 26 do backlog gap-simplesvet) — `tutor_user_id` já foi resolvido
 * e confirmado como cliente deste profissional por `PetTutorResolver` na validação; este
 * provisionador só materializa o registro, sem regra de negócio adicional.
 */
final class ImportedPetProvisioner
{
    /**
     * @param  array{tutor_user_id: int, name: string, species: string, breed: ?string, breed_id: ?int, coat_colors: ?list<string>, gender: string, weight: ?float, birth_date: ?string, neutered: ?bool, microchip_number: ?string}  $normalized
     */
    public function create(array $normalized): Pet
    {
        return Pet::create([
            'user_id' => $normalized['tutor_user_id'],
            'name' => $normalized['name'],
            'species' => $normalized['species'],
            'breed' => $normalized['breed'],
            'breed_id' => $normalized['breed_id'],
            'coat_colors' => $normalized['coat_colors'],
            'gender' => $normalized['gender'],
            'weight' => $normalized['weight'],
            'birth_date' => $normalized['birth_date'],
            'neutered' => $normalized['neutered'] ?? false,
            'microchip_number' => $normalized['microchip_number'],
        ]);
    }
}
