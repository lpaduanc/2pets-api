<?php

namespace App\Services\Import;

use App\Models\Pet;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pet por (tutor + nome + espécie) — regra 5 da spec 26 (item "clientes/pets" da lista de
 * `DuplicateDetector`s). Nome comparado sem diferenciar caixa: "Rex"/"rex" é o mesmo pet.
 */
final class PetDuplicateDetector
{
    public function findExisting(int $tutorId, string $name, string $species): ?Pet
    {
        return $this->byTutorAndName($tutorId, $name)->where('species', $species)->first();
    }

    /**
     * Usado por `VaccinationImportValidator`, que localiza o pet do tutor pelo nome sem
     * conhecer (nem precisar conhecer) a espécie cadastrada.
     */
    public function findByTutorAndName(int $tutorId, string $name): ?Pet
    {
        return $this->byTutorAndName($tutorId, $name)->first();
    }

    /**
     * @return Builder<Pet>
     */
    private function byTutorAndName(int $tutorId, string $name): Builder
    {
        return Pet::query()
            ->where('user_id', $tutorId)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)]);
    }
}
