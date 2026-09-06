<?php

namespace App\Http\Controllers;

use App\Enums\ProfessionalType;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use App\Services\CpfValidationService;
use App\Services\CrmvValidationService;
use App\Services\Location\GeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

            // Google Places coords (optional; if provided, skip server-side geocoding)
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',

            // Optional profile photo (multipart upload). Accepts the same mime types
            // declared on the User media collection 'avatar'.
            'avatar' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        // Validate CPF
        $cpfService = new CpfValidationService;
        if (! $cpfService->validate($validated['cpf'])) {
            return response()->json(['errors' => ['cpf' => ['CPF inválido']]], 422);
        }

        $updateData = [
            'cpf' => $validated['cpf'], // User model mutator normalizes to digits-only.
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
        ];

        // Prefer coords from Google Places (pulled at autocomplete time — more accurate
        // than re-geocoding the free-text address). Fall back to server-side geocoding.
        $coords = null;
        if (isset($validated['latitude'], $validated['longitude'])) {
            $coords = [
                'latitude' => (float) $validated['latitude'],
                'longitude' => (float) $validated['longitude'],
            ];
        } else {
            $coords = $this->geocodeAddress($validated);
        }

        if ($coords) {
            $updateData['latitude'] = $coords['latitude'];
            $updateData['longitude'] = $coords['longitude'];
        }

        $user = $request->user();
        // location (geography) e sincronizada automaticamente pelo trait HasGeoPoint
        // quando latitude/longitude mudam nesta escrita.
        $user->update($updateData);

        // Attach profile photo via Spatie Media Library (singleFile collection — replaces any existing avatar).
        // Non-blocking: a failed upload should not break registration; only log and move on.
        if ($request->hasFile('avatar')) {
            try {
                $user->addMediaFromRequest('avatar')->toMediaCollection('avatar');
            } catch (\Throwable $e) {
                Log::warning('RegistrationCompletionController: Failed to store tutor avatar', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Profile completed successfully!',
            'user' => $user->fresh(),
        ]);
    }

    /**
     * `professional_type` nunca é lido de novo do request nem redigitado aqui — o único
     * ponto de verdade é `ProfessionalType::tryFrom($user->user_type)`, o mesmo enum que
     * `RegisterRequest` já validou na etapa 1 do cadastro.
     */
    public function completeProfessional(Request $request)
    {
        $user = $request->user();
        $professionalType = ProfessionalType::tryFrom($user->user_type);

        if ($professionalType === null) {
            return response()->json(['message' => 'Invalid user type'], 400);
        }

        return $professionalType === ProfessionalType::VET
            ? $this->completeVet($request, $user)
            : $this->completeGenericProfessional($request, $user, $professionalType);
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

            // Academic
            'university' => 'required|string',
            'graduation_year' => 'required|integer|min:1950|max:'.date('Y'),
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
        $cpfService = new CpfValidationService;
        if (! $cpfService->validate($validated['cpf'])) {
            return response()->json(['errors' => ['cpf' => ['CPF inválido']]], 422);
        }

        // Validate CRMV
        $crmvService = new CrmvValidationService;
        if (! $crmvService->validateFormat($validated['crmv'], $validated['crmv_state'])) {
            return response()->json(['errors' => ['crmv' => ['CRMV inválido']]], 422);
        }

        // Geocode the address (non-blocking)
        $coords = $this->geocodeAddress($validated);

        // Update user
        $updateData = [
            'cpf' => $validated['cpf'], // User model mutator normalizes to digits-only.
            'birth_date' => $validated['birth_date'],
            'address' => $validated['address'],
            'number' => $validated['number'],
            'complement' => $validated['complement'] ?? null,
            'neighborhood' => $validated['neighborhood'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'zip_code' => $validated['zip_code'],
            'profile_completed' => true,
        ];

        if ($coords) {
            $updateData['latitude'] = $coords['latitude'];
            $updateData['longitude'] = $coords['longitude'];
        }

        // location (geography) e sincronizada automaticamente pelo trait HasGeoPoint.
        $user->update($updateData);

        // Create professional record
        Professional::create([
            'user_id' => $user->id,
            'professional_type' => ProfessionalType::VET,
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

    private function completeGenericProfessional(Request $request, User $user, ProfessionalType $professionalType)
    {
        \Log::info('=== COMPLETE GENERIC PROFESSIONAL START ===', [
            'user_id' => $user->id,
            'user_type' => $user->user_type,
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
        if (in_array($professionalType, [ProfessionalType::CLINIC, ProfessionalType::LABORATORY], true)) {
            // Allow either an ID (existing user) OR details (name + crmv)
            $rules['technical_responsible_id'] = 'nullable|exists:users,id';
            $rules['technical_responsible_name'] = 'required_without:technical_responsible_id|nullable|string';
            $rules['technical_responsible_crmv'] = 'required_without:technical_responsible_id|nullable|string';
            $rules['technical_responsible_crmv_state'] = 'required_without:technical_responsible_id|nullable|string|size:2';
        }

        $validated = $request->validate($rules);

        \Log::info('Validation passed', ['cnpj' => $validated['cnpj']]);

        // Geocode the address (non-blocking)
        $coords = $this->geocodeAddress($validated);

        // Update user (business address)
        $updateData = [
            'address' => $validated['address'],
            'number' => $validated['number'],
            'complement' => $validated['complement'] ?? null,
            'neighborhood' => $validated['neighborhood'],
            'city' => $validated['city'],
            'state' => $validated['state'],
            'zip_code' => $validated['zip_code'],
            'profile_completed' => true,
        ];

        if ($coords) {
            $updateData['latitude'] = $coords['latitude'];
            $updateData['longitude'] = $coords['longitude'];
        }

        // location (geography) e sincronizada automaticamente pelo trait HasGeoPoint.
        $user->update($updateData);

        // BULLETPROOF FIX: Use updateOrCreate to handle auto-saved records
        $professionalData = [
            'user_id' => $user->id,
            'professional_type' => $professionalType,
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

        \Log::info('Creating/Updating professional', [
            'user_id' => $user->id,
            'cnpj' => $professionalData['cnpj'],
        ]);

        // Check if record exists (from auto-save)
        $existing = Professional::where('user_id', $user->id)->first();

        if ($existing) {
            \Log::info('Found existing professional record - UPDATING', [
                'professional_id' => $existing->id,
                'existing_cnpj' => $existing->cnpj,
                'new_cnpj' => $professionalData['cnpj'],
            ]);

            $existing->update($professionalData);
        } else {
            \Log::info('No existing record - CREATING NEW');
            Professional::create($professionalData);
        }

        \Log::info('=== COMPLETE GENERIC PROFESSIONAL END (SUCCESS) ===');

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

    /**
     * Build a full address string from validated data and geocode it.
     * Returns coordinates array or null if geocoding fails.
     * This is non-blocking: failures are logged but do not interrupt registration.
     *
     * @param  array  $validated  Validated request data containing address fields
     * @return array{latitude: float, longitude: float}|null
     */
    private function geocodeAddress(array $validated): ?array
    {
        try {
            $parts = array_filter([
                $validated['address'] ?? null,
                $validated['number'] ?? null,
                $validated['neighborhood'] ?? null,
                $validated['city'] ?? null,
                $validated['state'] ?? null,
                $validated['zip_code'] ?? null,
            ]);

            $fullAddress = implode(', ', $parts);

            $geocodingService = app(GeocodingService::class);

            return $geocodingService->geocode($fullAddress);
        } catch (\Throwable $e) {
            Log::warning('RegistrationCompletionController: Geocoding failed, skipping coordinates', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
