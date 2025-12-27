<?php

namespace App\Services;

class CrmvValidationService
{
    /**
     * Validate CRMV format (e.g., CRMV/SP 12345 or CRMV-SP 12345)
     */
    public function validateFormat(string $crmv, string $state): bool
    {
        // Clean input
        $crmv = trim($crmv);
        $state = strtoupper(trim($state));

        // Basic validation: must contain numbers
        if (!preg_match('/\d+/', $crmv)) {
            return false;
        }

        // If user just typed numbers, we assume it's valid if length is reasonable
        if (ctype_digit($crmv)) {
            return strlen($crmv) >= 4 && strlen($crmv) <= 6;
        }

        // Check for standard format: CRMV/XX 12345 or CRMV-XX 12345
        // Allow optional spaces
        $pattern = '/^CRMV[\/-]' . $state . '\s*\d{4,6}$/i';

        // Also allow just the number part if it matches the state prefix elsewhere or implied
        // But strictly speaking, we want to validate the full string if provided

        return preg_match($pattern, $crmv) === 1;
    }

    /**
     * Format CRMV to standard format (CRMV/XX 12345)
     */
    public function format(string $crmv, string $state): string
    {
        // Extract numbers
        $number = preg_replace('/[^0-9]/', '', $crmv);
        $state = strtoupper($state);

        return 'CRMV/' . $state . ' ' . $number;
    }
}
