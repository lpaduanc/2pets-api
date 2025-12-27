<?php

namespace App\Services;

class CpfValidationService
{
    /**
     * Validate CPF number with check digit verification
     */
    public function validate(string $cpf): bool
    {
        // Remove formatting
        $cpf = preg_replace('/[^0-9]/', '', $cpf);

        // Check length
        if (strlen($cpf) != 11) {
            return false;
        }

        // Check for known invalid CPFs (all same digits)
        if (preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }

        // Validate first check digit
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += $cpf[$i] * (10 - $i);
        }
        $remainder = $sum % 11;
        $digit1 = ($remainder < 2) ? 0 : 11 - $remainder;

        if ($cpf[9] != $digit1) {
            return false;
        }

        // Validate second check digit
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += $cpf[$i] * (11 - $i);
        }
        $remainder = $sum % 11;
        $digit2 = ($remainder < 2) ? 0 : 11 - $remainder;

        if ($cpf[10] != $digit2) {
            return false;
        }

        return true;
    }

    /**
     * Format CPF to standard format (###.###.###-##)
     */
    public function format(string $cpf): string
    {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);

        if (strlen($cpf) != 11) {
            return $cpf;
        }

        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $cpf);
    }

    /**
     * Remove formatting from CPF
     */
    public function clean(string $cpf): string
    {
        return preg_replace('/[^0-9]/', '', $cpf);
    }
}
