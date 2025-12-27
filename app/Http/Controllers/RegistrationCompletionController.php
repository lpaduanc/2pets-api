<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Models\Company;
use App\Models\User;
use App\Services\CpfValidationService;
use App\Services\CrmvValidationService;
use Illuminate\Http\Request;

class RegistrationCompletionController extends Controller
{
    public function completeTutor(Request $request)
    {
        $validated = $request->validate([
            // Personal
            'cpf' => 'required|string',
            'birth_date' => 'required|date',
            'gender' => 'nullable|string|in:male,female,other,not_specified',
            'occupation' => 'nullable|string|max:255',

            // Address
            'address' => 'required|string',
            'number' => 'required|string',
            'complement' => 'nullable|string',
            'neighborhood' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string|size:2',
            'zip_code' => 'required|string',
        ]);

        // Validate CPF
        $cpfService = new CpfValidationService();
        if (!$cpfService->validate($validated['cpf'])) {
            return response()->json(['errors' => ['cpf' => ['CPF inválido']]], 422);
        }

        $user = $request->user();
        $user->update([
            'cpf' => $cpfService->format($validated['cpf']),
            'birth_date' => $validated['birth_date'],
            'gender' => $validated['gender'] ?? null,
            'occupation' => $validated['occupation'] ?? null,
            'address' => $validated['address'],
            'number' => $validated['number'],
            'complement' => $validated['complement'] ?? null,
            'neighborhood' => $validated['neighborhood'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'zip_code' => $validated['zip_code'],
            'profile_completed' => true,
        ]);

        return response()->json([
            'message' => 'Profile completed successfully!',
            'user' => $user->fresh(),
        ]);
    }

    public function completeProfessional(Request $request)
    {
        // Multi-step validation based on professional_type
        $user = $request->user();

        switch ($user->user_type) {
            case 'vet':
                return $this->completeVet($request, $user);
            case 'clinic':
            case 'laboratory':
            case 'petshop':
            case 'pet_hotel':
            case 'grooming':
            case 'training':
                return $this->completeGenericProfessional($request, $user);
            default:
                return response()->json(['message' => 'Invalid user type'], 400);
        }
    }

    private function completeVet(Request $request, User $user)
    {
        $validated = $request->validate([
            // Personal
            'cpf' => 'required|string',
            'birth_date' => 'required|date',
            'address' => 'required|string',
            'number' => 'required|string',
            'complement' => 'nullable|string',
            'neighborhood' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string|size:2',
            'zip_code' => 'required|string',
            // TODO: Re-enable when implementing geocoding
            // 'latitude' => 'required|numeric',
            // 'longitude' => 'required|numeric',

            // Academic
            'university' => 'required|string',
            'graduation_year' => 'required|integer|min:1950|max:' . date('Y'),
            'courses' => 'nullable|array',

            // Professional
            'crmv' => 'required|string',
            'crmv_state' => 'required|string|size:2',
            'specialties' => 'nullable|array',
            'experience_years' => 'required|integer|min:0',
            'service_radius_km' => 'nullable|integer|min:1',
            'opening_hours' => 'required|string', // Format HH:mm
            'closing_hours' => 'required|string', // Format HH:mm
            'working_days' => 'required|array',
            'description' => 'nullable|string',
        ]);

        // Validate CPF
        $cpfService = new CpfValidationService();
        if (!$cpfService->validate($validated['cpf'])) {
            return response()->json(['errors' => ['cpf' => ['CPF inválido']]], 422);
        }

        // Validate CRMV
        $crmvService = new CrmvValidationService();
        if (!$crmvService->validateFormat($validated['crmv'], $validated['crmv_state'])) {
            return response()->json(['errors' => ['crmv' => ['CRMV inválido']]], 422);
        }

        // Update user
        $user->update([
            'cpf' => $cpfService->format($validated['cpf']),
            'birth_date' => $validated['birth_date'],
            'address' => $validated['address'],
            'number' => $validated['number'],
            'complement' => $validated['complement'] ?? null,
            'neighborhood' => $validated['neighborhood'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'zip_code' => $validated['zip_code'],
            // TODO: Re-enable when implementing geocoding
            // 'latitude' => $validated['latitude'],
            // 'longitude' => $validated['longitude'],
            'profile_completed' => true,
        ]);

        // Create professional record
        Professional::create([
            'user_id' => $user->id,
            'professional_type' => 'vet',
            'crmv' => $crmvService->format($validated['crmv'], $validated['crmv_state']),
            'crmv_state' => $validated['crmv_state'],
            'university' => $validated['university'],
            'graduation_year' => $validated['graduation_year'],
            'courses' => $validated['courses'] ?? [],
            'specialties' => $validated['specialties'] ?? [],
            'experience_years' => $validated['experience_years'],
            'service_radius_km' => $validated['service_radius_km'] ?? null,
            'opening_hours' => $validated['opening_hours'],
            'closing_hours' => $validated['closing_hours'],
            'working_days' => $validated['working_days'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'message' => 'Vet profile completed successfully!',
            'user' => $user->fresh()->load('professional'),
        ]);
    }

    private function completeGenericProfessional(Request $request, User $user)
    {
        \Log::info("=== COMPLETE GENERIC PROFESSIONAL START ===", [
            'user_id' => $user->id,
            'user_type' => $user->user_type
        ]);

        $rules = [
            // Business Info
            'business_name' => 'required|string',
            'cnpj' => 'required|string',
            'address' => 'required|string',
            'number' => 'required|string',
            'complement' => 'nullable|string',
            'neighborhood' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string|size:2',
            'zip_code' => 'required|string',
            // TODO: Re-enable when implementing geocoding
            // 'latitude' => 'required|numeric',
            // 'longitude' => 'required|numeric',

            // Operations
            'opening_hours' => 'required|string',
            'closing_hours' => 'required|string',
            'working_days' => 'required|array',
            'description' => 'nullable|string',
            'services_offered' => 'nullable|array',
            'products_sold' => 'nullable|array',
            'equipment' => 'nullable|array',
            'certifications' => 'nullable|array',
        ];

        // Add technical responsible for Clinic and Laboratory
        if (in_array($user->user_type, ['clinic', 'laboratory'])) {
            // Allow either an ID (existing user) OR details (name + crmv)
            $rules['technical_responsible_id'] = 'nullable|exists:users,id';
            $rules['technical_responsible_name'] = 'required_without:technical_responsible_id|nullable|string';
            $rules['technical_responsible_crmv'] = 'required_without:technical_responsible_id|nullable|string';
            $rules['technical_responsible_crmv_state'] = 'required_without:technical_responsible_id|nullable|string|size:2';
        }

        $validated = $request->validate($rules);

        \Log::info("Validation passed", ['cnpj' => $validated['cnpj']]);

        // Update user (business address)
        $user->update([
            'address' => $validated['address'],
            'number' => $validated['number'],
            'complement' => $validated['complement'] ?? null,
            'neighborhood' => $validated['neighborhood'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'zip_code' => $validated['zip_code'],
            // TODO: Re-enable when implementing geocoding
            // 'latitude' => $validated['latitude'],
            // 'longitude' => $validated['longitude'],
            'profile_completed' => true,
        ]);

        // BULLETPROOF FIX: Use updateOrCreate to handle auto-saved records
        $professionalData = [
            'user_id' => $user->id,
            'professional_type' => $user->user_type,
            'business_name' => $validated['business_name'],
            'cnpj' => $validated['cnpj'],
            'opening_hours' => $validated['opening_hours'],
            'closing_hours' => $validated['closing_hours'],
            'working_days' => $validated['working_days'],
            'description' => $validated['description'] ?? null,
            'services_offered' => $validated['services_offered'] ?? [],
            'products_sold' => $validated['products_sold'] ?? [],
            'equipment' => $validated['equipment'] ?? [],
            'certifications' => $validated['certifications'] ?? [],
            'technical_responsible_id' => $validated['technical_responsible_id'] ?? null,
            'technical_responsible_name' => $validated['technical_responsible_name'] ?? null,
            'technical_responsible_crmv' => $validated['technical_responsible_crmv'] ?? null,
            'technical_responsible_crmv_state' => $validated['technical_responsible_crmv_state'] ?? null,
        ];

        \Log::info("Creating/Updating professional", [
            'user_id' => $user->id,
            'cnpj' => $professionalData['cnpj']
        ]);

        // Check if record exists (from auto-save)
        $existing = Professional::where('user_id', $user->id)->first();

        if ($existing) {
            \Log::info("Found existing professional record - UPDATING", [
                'professional_id' => $existing->id,
                'existing_cnpj' => $existing->cnpj,
                'new_cnpj' => $professionalData['cnpj']
            ]);

            $existing->update($professionalData);
        } else {
            \Log::info("No existing record - CREATING NEW");
            Professional::create($professionalData);
        }

        \Log::info("=== COMPLETE GENERIC PROFESSIONAL END (SUCCESS) ===");

        return response()->json([
            'message' => 'Professional profile completed successfully!',
            'user' => $user->fresh()->load('professional'),
        ]);
    }

    public function completeCompany(Request $request)
    {
        $validated = $request->validate([
            // Company Info
            'company_name' => 'required|string',
            'cnpj' => 'required|string',
            'contact_name' => 'required|string',
            'contact_position' => 'nullable|string',
            'phone' => 'required|string',
            'employee_count' => 'required|string',
            'website' => 'nullable|string',

            // Address
            'address' => 'required|string',
            'number' => 'required|string',
            'complement' => 'nullable|string',
            'neighborhood' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string|size:2',
            'zip_code' => 'required|string',

            // Benefit Details
            'benefit_type' => 'required|string',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        // Update user
        $user->update([
            'address' => $validated['address'],
            'number' => $validated['number'],
            'complement' => $validated['complement'] ?? null,
            'neighborhood' => $validated['neighborhood'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'zip_code' => $validated['zip_code'],
            'cnpj' => $validated['cnpj'],
            'employee_count' => $validated['employee_count'],
            'profile_completed' => true,
            'registration_status' => 'completed',
        ]);

        // Create or update company record
        Company::updateOrCreate(
            ['user_id' => $user->id],
            [
                'company_name' => $validated['company_name'],
                'cnpj' => $validated['cnpj'],
                'contact_name' => $validated['contact_name'],
                'contact_position' => $validated['contact_position'] ?? null,
                'phone' => $validated['phone'],
                'website' => $validated['website'] ?? null,
                'employee_count' => $validated['employee_count'],
                'benefit_type' => $validated['benefit_type'],
                'notes' => $validated['notes'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Company profile completed successfully!',
            'user' => $user->fresh()->load('company'),
        ]);
    }
}
