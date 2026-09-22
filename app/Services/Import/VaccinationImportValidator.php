<?php

namespace App\Services\Import;

use App\Contracts\Import\ImportRowValidator;
use App\Enums\ImmunizationGroup;
use App\Models\DataImport;
use App\Models\ImmunizationProduct;
use App\Models\Pet;
use App\Models\User;

/**
 * Valida/normaliza uma linha de importação de histórico de vacina (item 26 do backlog
 * gap-simplesvet) — "casando com pet já importado/existente" é bloqueante desta linha (regra
 * 3); o nome da vacina é casado por similaridade com o catálogo global de
 * `ImmunizationProduct` (`group = vaccine`, `organization_id = null`) só como correção
 * ortográfica (`vaccinations.vaccine_name` é texto livre, sem FK para o catálogo — não há
 * "item novo" para criar aqui, ao contrário de espécie/raça). Migrado de `VaccineCatalog`
 * (legado, mantido só para histórico — ver contrato 13) para `ImmunizationProduct`, que o
 * substitui (contrato `docs/gap-simplesvet/contratos/13-contrato-api.md`).
 */
final class VaccinationImportValidator implements ImportRowValidator
{
    public function __construct(
        private readonly BrazilianFormatParser $formatParser,
        private readonly PetTutorResolver $tutorResolver,
        private readonly PetDuplicateDetector $petLookup,
        private readonly CatalogSimilarityMatcher $similarityMatcher,
    ) {}

    /**
     * @param  array<string, string>  $row
     * @return array{valid: bool, normalized: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $row, DataImport $import): array
    {
        $errors = [];
        $pet = $this->resolvePet($row, $import, $errors);
        $vaccineName = $this->resolveVaccineName(trim($row['vaccine_name'] ?? ''), $pet, $errors);
        $applicationDate = $this->parseRequiredDate($row['application_date'] ?? '', 'Data de aplicação', $errors);

        return [
            'valid' => $errors === [],
            'normalized' => [
                'pet_id' => $pet?->id,
                'vaccine_name' => $vaccineName,
                'manufacturer' => trim($row['manufacturer'] ?? '') ?: null,
                'batch_number' => trim($row['batch_number'] ?? '') ?: null,
                'application_date' => $applicationDate,
                'expiry_date' => $this->parseOptionalDate($row['expiry_date'] ?? '', 'Validade', $errors),
                'next_dose_date' => $this->parseOptionalDate($row['next_dose_date'] ?? '', 'Próxima dose', $errors),
                'dose_number' => $this->parseDoseNumber($row['dose_number'] ?? ''),
                'notes' => trim($row['notes'] ?? '') ?: null,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  list<string>  $errors
     */
    private function resolvePet(array $row, DataImport $import, array &$errors): ?Pet
    {
        $tutor = $this->resolveTutor($row, $import, $errors);
        $petName = trim($row['pet_name'] ?? '');

        if ($tutor === null) {
            return null;
        }

        if ($petName === '') {
            $errors[] = 'Nome do pet é obrigatório.';

            return null;
        }

        $pet = $this->petLookup->findByTutorAndName($tutor->id, $petName);
        if ($pet === null) {
            $errors[] = 'Pet não encontrado para o tutor informado. Importe pets primeiro.';
        }

        return $pet;
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
            $errors[] = 'Tutor não encontrado para os dados informados. Importe clientes antes de vacinas.';
        }

        return $tutor;
    }

    /**
     * @param  list<string>  $errors
     */
    private function resolveVaccineName(string $rawName, ?Pet $pet, array &$errors): string
    {
        if ($rawName === '') {
            $errors[] = 'Nome da vacina é obrigatório.';

            return '';
        }

        $catalogNames = ImmunizationProduct::query()
            ->where('group', ImmunizationGroup::VACCINE)
            ->whereNull('organization_id')
            ->when(
                $pet !== null,
                fn ($query) => $query->whereHas(
                    'speciesLinks',
                    fn ($speciesQuery) => $speciesQuery->where('species', $pet->species),
                ),
            )
            ->pluck('name')
            ->all();

        return $this->similarityMatcher->bestMatch($rawName, $catalogNames) ?? $rawName;
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseRequiredDate(string $rawDate, string $fieldLabel, array &$errors): ?string
    {
        if (trim($rawDate) === '') {
            $errors[] = "{$fieldLabel} é obrigatória.";

            return null;
        }

        return $this->parseOptionalDate($rawDate, $fieldLabel, $errors);
    }

    /**
     * @param  list<string>  $errors
     */
    private function parseOptionalDate(string $rawDate, string $fieldLabel, array &$errors): ?string
    {
        if (trim($rawDate) === '') {
            return null;
        }

        $date = $this->formatParser->parseDate($rawDate);
        if ($date === null) {
            $errors[] = "{$fieldLabel} inválida.";
        }

        return $date;
    }

    private function parseDoseNumber(string $rawDoseNumber): ?int
    {
        return ctype_digit(trim($rawDoseNumber)) ? (int) $rawDoseNumber : null;
    }
}
