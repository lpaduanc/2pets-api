<?php

namespace App\Services\Import;

use App\Contracts\Import\ImportRowValidator;
use App\Models\DataImport;
use App\Models\User;

/**
 * Valida/normaliza uma linha de importação de pets (item 26 do backlog gap-simplesvet) —
 * "vinculado a cliente já importado/existente" é regra bloqueante desta linha (não do lote
 * inteiro, regra 3); espécie/raça/pelagem nunca bloqueiam (regra 4), casadas por
 * `PetSpeciesSynonymResolver`/`BreedMatcher`/`CoatCatalogResolver`.
 */
final class PetImportValidator implements ImportRowValidator
{
    public function __construct(
        private readonly BrazilianFormatParser $formatParser,
        private readonly PetSpeciesSynonymResolver $speciesResolver,
        private readonly BreedMatcher $breedMatcher,
        private readonly CoatCatalogResolver $coatResolver,
        private readonly PetTutorResolver $tutorResolver,
    ) {}

    /**
     * @param  array<string, string>  $row
     * @return array{valid: bool, normalized: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $row, DataImport $import): array
    {
        $errors = [];
        $name = trim($row['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Nome do pet é obrigatório.';
        }

        $tutor = $this->resolveTutor($row, $import, $errors);
        $species = $this->speciesResolver->resolve($row['species'] ?? '');
        $breed = $this->breedMatcher->match($row['breed'] ?? '', $species);
        $weight = $this->parseWeight($row['weight'] ?? '', $errors);
        $birthDate = $this->parseBirthDate($row['birth_date'] ?? '', $errors);

        return [
            'valid' => $errors === [],
            'normalized' => [
                'tutor_user_id' => $tutor?->id,
                'name' => $name,
                'species' => $species->value,
                'breed' => $breed['breed_name'],
                'breed_id' => $breed['breed_id'],
                'coat_colors' => $this->parseCoatColors($row['coat'] ?? '', $import),
                'gender' => $this->parseGender($row['gender'] ?? ''),
                'weight' => $weight,
                'birth_date' => $birthDate,
                'neutered' => $this->parseNeutered($row['neutered'] ?? ''),
                'microchip_number' => trim($row['microchip_number'] ?? '') ?: null,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     */
    private function resolveTutor(array $row, DataImport $import, array &$errors): ?User
    {
        $professionalId = $import->professional_id ?? $import->user_id;
        $identifiers = [
            'cpf' => trim($row['tutor_cpf'] ?? '') ?: null,
            'email' => trim($row['tutor_email'] ?? '') ?: null,
            'phone' => preg_replace('/\D/', '', $row['tutor_phone'] ?? '') ?: null,
        ];

        $tutor = $this->tutorResolver->resolve($identifiers, $professionalId);
        if ($tutor === null) {
            $errors[] = 'Tutor não encontrado para os dados informados. Importe clientes antes de pets.';
        }

        return $tutor;
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseWeight(string $rawWeight, array &$errors): ?float
    {
        if (trim($rawWeight) === '') {
            return null;
        }

        $weight = $this->formatParser->parseDecimal($rawWeight);
        if ($weight === null) {
            $errors[] = 'Peso inválido.';
        }

        return $weight;
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseBirthDate(string $rawDate, array &$errors): ?string
    {
        if (trim($rawDate) === '') {
            return null;
        }

        $date = $this->formatParser->parseDate($rawDate);
        if ($date === null) {
            $errors[] = 'Data de nascimento inválida.';
        }

        return $date;
    }

    /**
     * @return list<string>|null
     */
    private function parseCoatColors(string $rawCoat, DataImport $import): ?array
    {
        $values = array_filter(array_map('trim', preg_split('/[;,]/', $rawCoat) ?: []));
        if ($values === []) {
            return null;
        }

        return $this->coatResolver->resolveAll(array_values($values), $import->user);
    }

    private function parseGender(string $rawGender): string
    {
        $normalized = mb_strtolower(trim($rawGender));

        return match (true) {
            in_array($normalized, ['macho', 'm', 'male'], true) => 'male',
            in_array($normalized, ['femea', 'fêmea', 'f', 'female'], true) => 'female',
            default => 'unknown',
        };
    }

    private function parseNeutered(string $rawNeutered): ?bool
    {
        $normalized = mb_strtolower(trim($rawNeutered));
        if ($normalized === '') {
            return null;
        }

        return in_array($normalized, ['sim', 's', 'yes', 'true', '1'], true);
    }
}
