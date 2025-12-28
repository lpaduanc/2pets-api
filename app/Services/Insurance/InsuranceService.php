<?php

namespace App\Services\Insurance;

use App\Models\InsuranceClaim;
use App\Models\PetInsurance;
use App\Models\InsurancePreAuthorization;
use App\Models\Appointment;
use Illuminate\Support\Facades\DB;

final class InsuranceService
{
    public function createClaim(
        PetInsurance $insurance,
        string $claimType,
        string $description,
        float $claimedAmount,
        \DateTime $incidentDate,
        ?Appointment $appointment = null
    ): InsuranceClaim {
        if (!$insurance->isActive()) {
            throw new \Exception('Insurance policy is not active');
        }

        $claimNumber = $this->generateClaimNumber();

        return InsuranceClaim::create([
            'pet_insurance_id' => $insurance->id,
            'appointment_id' => $appointment?->id,
            'claim_number' => $claimNumber,
            'claim_type' => $claimType,
            'description' => $description,
            'claimed_amount' => $claimedAmount,
            'incident_date' => $incidentDate,
            'status' => 'draft',
        ]);
    }

    public function submitClaim(InsuranceClaim $claim): void
    {
        DB::transaction(function () use ($claim) {
            $claim->submit();

            // In a real implementation, this would call the insurance provider's API
            // For now, we'll just mark it as under review
            $claim->update(['status' => 'under_review']);
        });
    }

    public function requestPreAuthorization(
        PetInsurance $insurance,
        string $procedureType,
        string $procedureDescription,
        float $estimatedCost,
        ?Appointment $appointment = null
    ): InsurancePreAuthorization {
        if (!$insurance->isActive()) {
            throw new \Exception('Insurance policy is not active');
        }

        $authorizationNumber = $this->generateAuthorizationNumber();

        return InsurancePreAuthorization::create([
            'pet_insurance_id' => $insurance->id,
            'appointment_id' => $appointment?->id,
            'authorization_number' => $authorizationNumber,
            'procedure_type' => $procedureType,
            'procedure_description' => $procedureDescription,
            'estimated_cost' => $estimatedCost,
            'status' => 'pending',
        ]);
    }

    public function verifyCoverage(PetInsurance $insurance, string $procedureType): array
    {
        $coverageDetails = $insurance->coverage_details ?? [];
        $exclusions = $insurance->exclusions ?? [];

        $isCovered = in_array($procedureType, $coverageDetails) 
            && !in_array($procedureType, $exclusions);

        $remainingLimit = $insurance->getRemainingAnnualLimit();

        return [
            'is_covered' => $isCovered,
            'remaining_annual_limit' => $remainingLimit,
            'deductible' => $insurance->deductible,
            'coverage_type' => $insurance->coverage_type,
        ];
    }

    public function getClaimsByInsurance(PetInsurance $insurance)
    {
        return $insurance->claims()
            ->with('appointment')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    private function generateClaimNumber(): string
    {
        return 'CLM-' . strtoupper(uniqid());
    }

    private function generateAuthorizationNumber(): string
    {
        return 'AUTH-' . strtoupper(uniqid());
    }
}

