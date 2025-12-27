<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RegistrationDraftController extends Controller
{
    /**
     * Save draft data for professional registration
     * Now saves to BOTH cache AND database for progressive completion
     */
    public function saveProfessionalDraft(Request $request)
    {
        try {
            $user = $request->user();
            $data = $request->all();
            
            // CRITICAL DEBUG: Log the received data
            \Log::info("=== PROFESSIONAL DRAFT SAVE START ===", [
                'user_id' => $user->id,
                'user_type' => $user->user_type,
                'payload_user_type' => $data['user_type'] ?? 'NOT_PRESENT',
                'has_cnpj' => isset($data['cnpj']),
                'cnpj' => $data['cnpj'] ?? 'none',
                'step' => $data['current_step'] ?? 'unknown'
            ]);
            
            // Store draft in cache for quick restoration (7 days)
            $cacheKey = "registration_draft_professional_{$user->id}";
            Cache::put($cacheKey, $data, now()->addDays(7));
            
            // ALSO save to database (progressive completion)
            $result = $this->updateProfessionalDatabase($user, $data);
            
            \Log::info("=== PROFESSIONAL DRAFT SAVE END ===", [
                'user_id' => $user->id,
                'result' => $result ? 'success' : 'failed'
            ]);
            
            return response()->json([
                'success' => true,
                'message' => 'Rascunho salvo com sucesso',
                'saved_at' => now()->toIso8601String(),
                'saved_to_database' => $result
            ]);
        } catch (\Exception $e) {
            \Log::error("=== PROFESSIONAL DRAFT SAVE ERROR ===", [
                'user_id' => $request->user()->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar rascunho: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Update professional data in database progressively
     * BULLETPROOF v2: Comprehensive logging and error handling
     */
    private function updateProfessionalDatabase($user, $data)
    {
        try {
            \Log::info("=== UPDATE PROFESSIONAL DATABASE START ===", [
                'user_id' => $user->id,
                'user_type_from_user' => $user->user_type,
                'user_type_from_data' => $data['user_type'] ?? 'NOT_IN_PAYLOAD'
            ]);
            
            // Get user_type from data (payload) or fallback to user model
            $userType = $data['user_type'] ?? $user->user_type;
            
            // Map fields from form to database
            $professionalFields = [
                'business_name',
                'cnpj',
                'website',
                'crmv',
                'crmv_state',
                'university',
                'graduation_year',
                'experience_years',
                'specialties',
                'courses',
                'service_radius_km',
                'opening_hours',
                'closing_hours',
                'working_days',
                'description',
                'technical_responsible_name',
                'technical_responsible_crmv',
                'technical_responsible_crmv_state',
                'services_offered',
                'products_sold',
                'equipment',
                'certifications'
            ];
            
            $professionalData = [];
            foreach ($professionalFields as $field) {
                if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                    $professionalData[$field] = $data[$field];
                }
            }
            
            \Log::info("Prepared professional data", [
                'field_count' => count($professionalData),
                'has_cnpj' => isset($professionalData['cnpj']),
                'cnpj_value' => $professionalData['cnpj'] ?? 'none'
            ]);
            
            if (!empty($professionalData)) {
                // BULLETPROOF: Check existing record OUTSIDE transaction first
                $existingRecord = \App\Models\Professional::where('user_id', $user->id)->first();
                
                \Log::info("Existing record check", [
                    'user_id' => $user->id,
                    'found' => $existingRecord ? 'YES' : 'NO',
                    'existing_id' => $existingRecord->id ?? 'none',
                    'existing_cnpj' => $existingRecord->cnpj ?? 'none'
                ]);
                
                if ($existingRecord) {
                    // RECORD EXISTS - Just UPDATE it (no transaction needed for simple update)
                    $existingRecord->update($professionalData);
                    
                    \Log::info("=== UPDATED EXISTING RECORD ===", [
                        'professional_id' => $existingRecord->id,
                        'user_id' => $user->id,
                        'cnpj' => $professionalData['cnpj'] ?? 'not_in_update'
                    ]);
                } else {
                    // RECORD DOESN'T EXIST - Need to create
                    \Log::info("Creating new professional record", [
                        'user_id' => $user->id,
                        'cnpj' => $professionalData['cnpj'] ?? 'none'
                    ]);
                    
                    // Check if CNPJ already exists for ANOTHER user
                    if (!empty($professionalData['cnpj'])) {
                        $cnpjCheck = \App\Models\Professional::where('cnpj', $professionalData['cnpj'])->first();
                        
                        if ($cnpjCheck) {
                            \Log::warning("=== CNPJ CONFLICT DETECTED ===", [
                                'cnpj' => $professionalData['cnpj'],
                                'existing_record_id' => $cnpjCheck->id,
                                'existing_user_id' => $cnpjCheck->user_id,
                                'new_user_id' => $user->id
                            ]);
                            
                            // Delete the conflicting record
                            $cnpjCheck->delete();
                            
                            \Log::info("Deleted conflicting record", [
                                'deleted_id' => $cnpjCheck->id
                            ]);
                        }
                    }
                    
                    // Now create safely
                    $professionalData['user_id'] = $user->id;
                    $professionalData['professional_type'] = $userType;
                    
                    $newRecord = \App\Models\Professional::create($professionalData);
                    
                    \Log::info("=== CREATED NEW RECORD ===", [
                        'professional_id' => $newRecord->id,
                        'user_id' => $user->id,
                        'cnpj' => $newRecord->cnpj
                    ]);
                }
            }
            
            // Update user fields
            $userFields = [
                'cpf',
                'birth_date',
                'phone' => 'professional_phone',
                'address',
                'number',
                'complement',
                'neighborhood',
                'city',
                'state',
                'zip_code'
            ];
            
            $userUpdateData = [];
            foreach ($userFields as $key => $field) {
                $sourceField = is_numeric($key) ? $field : $field;
                $targetField = is_numeric($key) ? $field : $key;
                
                if (isset($data[$sourceField]) && $data[$sourceField] !== '' && $data[$sourceField] !== null) {
                    $userUpdateData[$targetField] = $data[$sourceField];
                }
            }
            
            if (!empty($userUpdateData)) {
                $user->update($userUpdateData);
            }
            
            \Log::info("=== UPDATE PROFESSIONAL DATABASE END (SUCCESS) ===");
            
            return true;
        } catch (\Exception $e) {
            \Log::error("=== UPDATE PROFESSIONAL DATABASE FAILED ===", [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            return false;
        }
    }
    
    /**
     * Load draft data for professional registration
     * Now also includes existing database data
     */
    public function loadProfessionalDraft(Request $request)
    {
        try {
            $user = $request->user();
            $cacheKey = "registration_draft_professional_{$user->id}";
            
            // Get draft from cache
            $draft = Cache::get($cacheKey);
            
            // Get existing data from database
            $existingData = $this->fetchProfessionalData($user);
            
            // Merge: existing data as base, draft overrides if newer
            $mergedData = $existingData;
            if ($draft) {
                $mergedData = array_merge($existingData, $draft);
            }
            
            return response()->json([
                'success' => true,
                'draft' => $mergedData,
                'has_draft' => !empty($mergedData),
                'has_database_data' => !empty($existingData),
                'source' => $draft ? 'draft_and_database' : 'database_only'
            ]);
        } catch (\Exception $e) {
            Log::error("Error loading professional draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar rascunho',
                'has_draft' => false
            ], 500);
        }
    }
    
    /**
     * Fetch existing professional data from database
     */
    private function fetchProfessionalData($user)
    {
        $data = [];
        
        // Get professional record
        $professional = \App\Models\Professional::where('user_id', $user->id)->first();
        
        if ($professional) {
            $data = [
                'business_name' => $professional->business_name,
                'cnpj' => $professional->cnpj,
                'website' => $professional->website,
                'crmv' => $professional->crmv,
                'crmv_state' => $professional->crmv_state,
                'university' => $professional->university,
                'graduation_year' => $professional->graduation_year,
                'experience_years' => $professional->experience_years,
                'specialties' => $professional->specialties ?? [],
                'courses' => $professional->courses ?? [],
                'service_radius_km' => $professional->service_radius_km,
                'opening_hours' => $professional->opening_hours,
                'closing_hours' => $professional->closing_hours,
                'working_days' => $professional->working_days ?? [1,2,3,4,5],
                'description' => $professional->description,
                'technical_responsible_name' => $professional->technical_responsible_name,
                'technical_responsible_crmv' => $professional->technical_responsible_crmv,
                'technical_responsible_crmv_state' => $professional->technical_responsible_crmv_state,
                'services_offered' => $professional->services_offered ?? [],
                'equipment' => $professional->equipment ?? [],
                'certifications' => $professional->certifications ?? [],
            ];
        }
        
        // Add user data
        $data['cpf'] = $user->cpf;
        $data['birth_date'] = $user->birth_date;
        $data['professional_phone'] = $user->phone;
        $data['address'] = $user->address;
        $data['number'] = $user->number;
        $data['complement'] = $user->complement;
        $data['neighborhood'] = $user->neighborhood;
        $data['city'] = $user->city;
        $data['state'] = $user->state;
        $data['zip_code'] = $user->zip_code;
        
        // SENIOR FIX: Fetch uploaded documents (corrected column names)
        $documents = \DB::table('documents')
            ->where('user_id', $user->id)
            ->select('id', 'document_type', 'file_path', 'file_name', 'created_at')
            ->get()
            ->toArray();
        
        if (!empty($documents)) {
            $data['documents'] = $documents;
        }
        
        // Remove null values
        return array_filter($data, fn($value) => $value !== null && $value !== '');
    }
    
    /**
     * Delete draft data for professional registration
     */
    public function deleteProfessionalDraft(Request $request)
    {
        try {
            $user = $request->user();
            $cacheKey = "registration_draft_professional_{$user->id}";
            
            Cache::forget($cacheKey);
            
            return response()->json([
                'success' => true,
                'message' => 'Rascunho removido'
            ]);
        } catch (\Exception $e) {
            Log::error("Error deleting professional draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover rascunho'
            ], 500);
        }
    }
    
    /**
     * Save draft data for company registration
     * Now saves to BOTH cache AND database for progressive completion
     */
    public function saveCompanyDraft(Request $request)
    {
        try {
            $user = $request->user();
            $data = $request->all();
            
            // Store draft in cache for quick restoration (7 days)
            $cacheKey = "registration_draft_company_{$user->id}";
            Cache::put($cacheKey, $data, now()->addDays(7));
            
            // ALSO save to database (progressive completion)
            $this->updateCompanyDatabase($user, $data);
            
            Log::info("Company draft saved for user {$user->id}");
            
            return response()->json([
                'success' => true,
                'message' => 'Rascunho salvo com sucesso',
                'saved_at' => now()->toIso8601String(),
                'saved_to_database' => true
            ]);
        } catch (\Exception $e) {
            Log::error("Error saving company draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar rascunho'
            ], 500);
        }
    }
    
    /**
     * Update company data in database progressively
     * Fixed: Properly handles existing records to avoid duplicate errors
     */
    private function updateCompanyDatabase($user, $data)
    {
        try {
            // Map fields from form to database
            $companyFields = [
                'company_name',
                'cnpj',
                'contact_name',
                'contact_position',
                'phone',
                'website',
                'employee_count',
                'benefit_type',
                'notes' => 'additional_notes',
                'legal_representative_name',
                'legal_representative_cpf',
                'legal_representative_birth_date',
                'legal_representative_phone',
                'company_website',
                'company_phone',
                'company_email',
                'company_address',
                'company_number',
                'company_complement',
                'company_neighborhood',
                'company_city',
                'company_state',
                'company_zip_code'
            ];
            
            $companyData = [];
            foreach ($companyFields as $key => $field) {
                $sourceField = is_numeric($key) ? $field : $field;
                $targetField = is_numeric($key) ? $field : $key;
                
                if (isset($data[$sourceField]) && $data[$sourceField] !== '' && $data[$sourceField] !== null) {
                    $companyData[$targetField] = $data[$sourceField];
                }
            }
            
            if (!empty($companyData)) {
                // SENIOR FIX: Check if record exists first, then update or create
                $company = \App\Models\Company::where('user_id', $user->id)->first();
                
                if ($company) {
                    // Record exists - UPDATE it
                    $company->update($companyData);
                } else {
                    // Record doesn't exist - CREATE it
                    $companyData['user_id'] = $user->id;
                    \App\Models\Company::create($companyData);
                }
            }
            
            // Update user fields
            $userUpdateData = [];
            
            if (isset($data['cnpj']) && $data['cnpj'] !== '') {
                $userUpdateData['cnpj'] = $data['cnpj'];
            }
            if (isset($data['employee_count']) && $data['employee_count'] !== '') {
                $userUpdateData['employee_count'] = $data['employee_count'];
            }
            if (isset($data['additional_notes']) && $data['additional_notes'] !== '') {
                $userUpdateData['additional_notes'] = $data['additional_notes'];
            }
            
            if (!empty($userUpdateData)) {
                $user->update($userUpdateData);
            }
            
            return true;
        } catch (\Exception $e) {
            \Log::error("Error updating company database: " . $e->getMessage(), [
                'user_id' => $user->id,
                'data' => $data
            ]);
            throw $e;
        }
    }
    
    /**
     * Load draft data for company registration
     * Now also includes existing database data
     */
    public function loadCompanyDraft(Request $request)
    {
        try {
            $user = $request->user();
            $cacheKey = "registration_draft_company_{$user->id}";
            
            // Get draft from cache
            $draft = Cache::get($cacheKey);
            
            // Get existing data from database
            $existingData = $this->fetchCompanyData($user);
            
            // Merge: existing data as base, draft overrides if newer
            $mergedData = $existingData;
            if ($draft) {
                $mergedData = array_merge($existingData, $draft);
            }
            
            return response()->json([
                'success' => true,
                'draft' => $mergedData,
                'has_draft' => !empty($mergedData),
                'has_database_data' => !empty($existingData),
                'source' => $draft ? 'draft_and_database' : 'database_only'
            ]);
        } catch (\Exception $e) {
            Log::error("Error loading company draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar rascunho',
                'has_draft' => false
            ], 500);
        }
    }
    
    /**
     * Fetch existing company data from database
     */
    private function fetchCompanyData($user)
    {
        $data = [];
        
        // Get company record
        $company = \App\Models\Company::where('user_id', $user->id)->first();
        
        if ($company) {
            $data = [
                'company_name' => $company->company_name,
                'cnpj' => $company->cnpj,
                'contact_name' => $company->contact_name,
                'contact_position' => $company->contact_position,
                'phone' => $company->phone,
                'website' => $company->website,
                'employee_count' => $company->employee_count,
                'benefit_type' => $company->benefit_type,
                'additional_notes' => $company->notes,
                'legal_representative_name' => $company->legal_representative_name,
                'legal_representative_cpf' => $company->legal_representative_cpf,
                'legal_representative_birth_date' => $company->legal_representative_birth_date,
                'legal_representative_phone' => $company->legal_representative_phone,
                'company_website' => $company->company_website,
                'company_phone' => $company->company_phone,
                'company_email' => $company->company_email,
                'company_address' => $company->company_address,
                'company_number' => $company->company_number,
                'company_complement' => $company->company_complement,
                'company_neighborhood' => $company->company_neighborhood,
                'company_city' => $company->company_city,
                'company_state' => $company->company_state,
                'company_zip_code' => $company->company_zip_code,
            ];
        }
        
        // Add user data
        $data['cnpj'] = $data['cnpj'] ?? $user->cnpj;
        $data['employee_count'] = $data['employee_count'] ?? $user->employee_count;
        $data['additional_notes'] = $data['additional_notes'] ?? $user->additional_notes;
        
        // Fetch uploaded documents
        $documents = \DB::table('documents')
            ->where('user_id', $user->id)
            ->select('id', 'document_type', 'file_path', 'file_name', 'created_at')
            ->get()
            ->toArray();
        
        if (!empty($documents)) {
            $data['documents'] = $documents;
        }
        
        // Remove null values
        return array_filter($data, fn($value) => $value !== null && $value !== '');
    }
    
    /**
     * Delete draft data for company registration
     */
    public function deleteCompanyDraft(Request $request)
    {
        try {
            $user = $request->user();
            $cacheKey = "registration_draft_company_{$user->id}";
            
            Cache::forget($cacheKey);
            
            return response()->json([
                'success' => true,
                'message' => 'Rascunho removido'
            ]);
        } catch (\Exception $e) {
            Log::error("Error deleting company draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover rascunho'
            ], 500);
        }
    }
    
    /**
     * Save draft data for tutor registration
     * Now saves to BOTH cache AND database for progressive completion
     */
    public function saveTutorDraft(Request $request)
    {
        try {
            $user = $request->user();
            $data = $request->all();
            
            // Store draft in cache for quick restoration (7 days)
            $cacheKey = "registration_draft_tutor_{$user->id}";
            Cache::put($cacheKey, $data, now()->addDays(7));
            
            // ALSO save to database (progressive completion)
            $this->updateTutorDatabase($user, $data);
            
            Log::info("Tutor draft saved for user {$user->id}");
            
            return response()->json([
                'success' => true,
                'message' => 'Rascunho salvo com sucesso',
                'saved_at' => now()->toIso8601String(),
                'saved_to_database' => true
            ]);
        } catch (\Exception $e) {
            Log::error("Error saving tutor draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao salvar rascunho'
            ], 500);
        }
    }
    
    /**
     * Update tutor data in database progressively
     */
    private function updateTutorDatabase($user, $data)
    {
        // Update user fields directly
        $tutorFields = [
            'cpf',
            'birth_date',
            'gender',
            'occupation',
            'address',
            'number',
            'complement',
            'neighborhood',
            'city',
            'state',
            'zip_code'
        ];
        
        $updateData = [];
        foreach ($tutorFields as $field) {
            if (isset($data[$field]) && $data[$field] !== '' && $data[$field] !== null) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (!empty($updateData)) {
            $user->update($updateData);
        }
        
        return true;
    }
    
    /**
     * Load draft data for tutor registration
     * Now also includes existing database data
     */
    public function loadTutorDraft(Request $request)
    {
        try {
            $user = $request->user();
            $cacheKey = "registration_draft_tutor_{$user->id}";
            
            // Get draft from cache
            $draft = Cache::get($cacheKey);
            
            // Get existing data from database
            $existingData = $this->fetchTutorData($user);
            
            // Merge: existing data as base, draft overrides if newer
            $mergedData = $existingData;
            if ($draft) {
                $mergedData = array_merge($existingData, $draft);
            }
            
            return response()->json([
                'success' => true,
                'draft' => $mergedData,
                'has_draft' => !empty($mergedData),
                'has_database_data' => !empty($existingData),
                'source' => $draft ? 'draft_and_database' : 'database_only'
            ]);
        } catch (\Exception $e) {
            Log::error("Error loading tutor draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar rascunho',
                'has_draft' => false
            ], 500);
        }
    }
    
    /**
     * Fetch existing tutor data from database
     */
    private function fetchTutorData($user)
    {
        $data = [
            'cpf' => $user->cpf,
            'birth_date' => $user->birth_date,
            'gender' => $user->gender,
            'occupation' => $user->occupation,
            'address' => $user->address,
            'number' => $user->number,
            'complement' => $user->complement,
            'neighborhood' => $user->neighborhood,
            'city' => $user->city,
            'state' => $user->state,
            'zip_code' => $user->zip_code,
        ];
        
        // Fetch uploaded documents
        $documents = \DB::table('documents')
            ->where('user_id', $user->id)
            ->select('id', 'document_type', 'file_path', 'file_name', 'created_at')
            ->get()
            ->toArray();
        
        if (!empty($documents)) {
            $data['documents'] = $documents;
        }
        
        // Remove null values
        return array_filter($data, fn($value) => $value !== null && $value !== '');
    }
    
    /**
     * Delete draft data for tutor registration
     */
    public function deleteTutorDraft(Request $request)
    {
        try {
            $user = $request->user();
            $cacheKey = "registration_draft_tutor_{$user->id}";
            
            Cache::forget($cacheKey);
            
            return response()->json([
                'success' => true,
                'message' => 'Rascunho removido'
            ]);
        } catch (\Exception $e) {
            Log::error("Error deleting tutor draft: " . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao remover rascunho'
            ], 500);
        }
    }
}

