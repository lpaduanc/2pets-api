<?php

namespace App\Services\Import;

use App\Contracts\Import\ImportRowValidator;
use App\DataTransferObjects\Cpf;
use App\Models\DataImport;

/**
 * Valida/normaliza uma linha de importação de clientes (item 26 do backlog gap-simplesvet).
 * Regra 3 da spec: linha inválida nunca trava o lote — este validador só devolve o resultado,
 * quem decide "seguir sem essa linha" é `DataImportService`.
 */
final class ClientImportValidator implements ImportRowValidator
{
    public function __construct(private readonly BrazilianFormatParser $formatParser) {}

    /**
     * @param  array<string, string>  $row  já remapeado para nome de campo canônico
     * @return array{valid: bool, normalized: array<string, mixed>, errors: list<string>}
     */
    public function validate(array $row, DataImport $import): array
    {
        $errors = [];
        $name = trim($row['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Nome é obrigatório.';
        }

        $cpf = $this->validateCpf($row['cpf'] ?? null, $errors);
        $email = $this->validateEmail($row['email'] ?? null, $errors);
        $phone = preg_replace('/\D/', '', $row['phone'] ?? '') ?: null;
        $birthDate = isset($row['birth_date']) ? $this->formatParser->parseDate($row['birth_date']) : null;

        return [
            'valid' => $errors === [],
            'normalized' => [
                'name' => $name,
                'cpf' => $cpf,
                'email' => $email,
                'phone' => $phone,
                'address' => trim($row['address'] ?? '') ?: null,
                'birth_date' => $birthDate,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateCpf(?string $rawCpf, array &$errors): ?string
    {
        if (empty($rawCpf)) {
            return null;
        }

        $cpf = Cpf::tryParse($rawCpf);
        if ($cpf === null) {
            $errors[] = 'CPF inválido.';

            return null;
        }

        return (string) $cpf;
    }

    /**
     * @param  list<string>  $errors
     */
    private function validateEmail(?string $rawEmail, array &$errors): ?string
    {
        $email = trim($rawEmail ?? '');
        if ($email === '') {
            return null;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'E-mail inválido.';

            return null;
        }

        return mb_strtolower($email);
    }
}
