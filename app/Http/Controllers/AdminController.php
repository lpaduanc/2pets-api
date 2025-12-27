<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Document;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function stats(Request $request)
    {
        try {
            $stats = [
                'users' => [
                    'total' => User::count(),
                    'tutors' => User::where('role', 'tutor')->count(),
                    'professionals' => User::where('role', 'professional')->count(),
                    'companies' => User::where('role', 'company')->count(),
                    'admins' => User::where('role', 'admin')->count(),
                ],
                'pending' => [
                    'companies' => User::where('role', 'company')
                        ->where('registration_status', 'pending')
                        ->count(),
                    'documents' => Document::where('verification_status', 'pending')->count(),
                ],
                'recent_registrations' => User::where('created_at', '>=', now()->subDays(7))
                    ->count(),
                'suspended_users' => User::where('is_suspended', true)->count(),
                'professionals_by_type' => Professional::select('professional_type', DB::raw('count(*) as count'))
                    ->groupBy('professional_type')
                    ->get()
                    ->pluck('count', 'professional_type'),
                'registration_trend' => $this->getRegistrationTrend(),
            ];

            return response()->json([
                'success' => true,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching admin stats', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar estatísticas'
            ], 500);
        }
    }

    /**
     * Get registration trend (last 7 days)
     */
    private function getRegistrationTrend()
    {
        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $count = User::whereDate('created_at', $date)->count();
            $trend[] = [
                'date' => $date,
                'count' => $count
            ];
        }
        return $trend;
    }

    /**
     * Get pending company registrations
     */
    public function pendingCompanies(Request $request)
    {
        try {
            $companies = User::where('role', 'company')
                ->where('registration_status', 'pending')
                ->with(['company', 'documents'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'companies' => $companies
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending companies', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar empresas pendentes'
            ], 500);
        }
    }

    /**
     * Approve company registration
     */
    public function approveCompany(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000'
        ]);

        try {
            DB::beginTransaction();

            $company = User::where('id', $id)
                ->where('role', 'company')
                ->firstOrFail();

            $company->update([
                'registration_status' => 'approved',
                'admin_notes' => $validated['notes'] ?? 'Aprovado',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now()
            ]);

            // Log the action
            Log::info('Company approved', [
                'admin_id' => $request->user()->id,
                'admin_email' => $request->user()->email,
                'company_id' => $company->id,
                'company_email' => $company->email,
                'company_name' => $company->name,
                'notes' => $validated['notes'] ?? 'N/A'
            ]);

            // TODO: Send approval email
            try {
                // Mail::to($company->email)->send(new CompanyApproved($company));
            } catch (\Exception $e) {
                Log::error('Failed to send approval email', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Empresa aprovada com sucesso',
                'company' => $company->fresh()->load('company')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error approving company', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'company_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao aprovar empresa'
            ], 500);
        }
    }

    /**
     * Reject company registration
     */
    public function rejectCompany(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:1000'
        ]);

        try {
            DB::beginTransaction();

            $company = User::where('id', $id)
                ->where('role', 'company')
                ->firstOrFail();

            $company->update([
                'registration_status' => 'rejected',
                'admin_notes' => $validated['notes'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now()
            ]);

            // Log the action
            Log::info('Company rejected', [
                'admin_id' => $request->user()->id,
                'admin_email' => $request->user()->email,
                'company_id' => $company->id,
                'company_email' => $company->email,
                'company_name' => $company->name,
                'reason' => $validated['notes']
            ]);

            // TODO: Send rejection email
            try {
                // Mail::to($company->email)->send(new CompanyRejected($company, $validated['notes']));
            } catch (\Exception $e) {
                Log::error('Failed to send rejection email', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registro da empresa rejeitado',
                'company' => $company->fresh()->load('company')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error rejecting company', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'company_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar empresa'
            ], 500);
        }
    }

    /**
     * Get pending documents for verification
     */
    public function pendingDocuments(Request $request)
    {
        try {
            $documents = Document::where('verification_status', 'pending')
                ->with(['user' => function ($query) {
                    $query->select('id', 'name', 'email', 'role', 'user_type');
                }])
                ->orderBy('created_at', 'desc')
                ->paginate(20);

            return response()->json([
                'success' => true,
                'documents' => $documents
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending documents', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar documentos pendentes'
            ], 500);
        }
    }

    /**
     * Verify a document
     */
    public function verifyDocument(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500'
        ]);

        try {
            $document = Document::findOrFail($id);

            $document->update([
                'verification_status' => 'verified',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $validated['notes'] ?? 'Documento verificado e aprovado'
            ]);

            Log::info('Document verified', [
                'admin_id' => $request->user()->id,
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'document_type' => $document->document_type
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento verificado com sucesso',
                'document' => $document->fresh()->load('user')
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying document', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'document_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar documento'
            ], 500);
        }
    }

    /**
     * Reject a document
     */
    public function rejectDocument(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:500'
        ]);

        try {
            $document = Document::findOrFail($id);

            $document->update([
                'verification_status' => 'rejected',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $validated['notes']
            ]);

            Log::info('Document rejected', [
                'admin_id' => $request->user()->id,
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'document_type' => $document->document_type,
                'reason' => $validated['notes']
            ]);

            // TODO: Send notification to user
            return response()->json([
                'success' => true,
                'message' => 'Documento rejeitado',
                'document' => $document->fresh()->load('user')
            ]);
        } catch (\Exception $e) {
            Log::error('Error rejecting document', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'document_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar documento'
            ], 500);
        }
    }

    /**
     * List all users with filters
     */
    public function listUsers(Request $request)
    {
        try {
            $query = User::with(['professional', 'company']);

            // Apply filters
            if ($request->has('role')) {
                $query->where('role', $request->role);
            }

            if ($request->has('registration_status')) {
                $query->where('registration_status', $request->registration_status);
            }

            if ($request->has('is_suspended')) {
                $query->where('is_suspended', $request->boolean('is_suspended'));
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            $users = $query->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'users' => $users
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing users', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar usuários'
            ], 500);
        }
    }

    /**
     * Show single user details
     */
    public function showUser(Request $request, $id)
    {
        try {
            $user = User::with(['professional', 'company', 'documents', 'pets'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'user' => $user
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user details', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar usuário'
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function updateUser(Request $request, $id)
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'phone' => 'sometimes|string|max:20',
            'admin_notes' => 'nullable|string|max:1000'
        ]);

        try {
            $user = User::findOrFail($id);
            $user->update($validated);

            Log::info('User updated by admin', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
                'changes' => $validated
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuário atualizado com sucesso',
                'user' => $user->fresh()->load(['professional', 'company'])
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating user', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar usuário'
            ], 500);
        }
    }

    /**
     * Suspend user
     */
    public function suspendUser(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500'
        ]);

        try {
            $user = User::findOrFail($id);

            // Cannot suspend admins
            if ($user->role === 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível suspender um administrador'
                ], 403);
            }

            $user->update([
                'is_suspended' => true,
                'admin_notes' => 'Suspenso: ' . $validated['reason']
            ]);

            Log::warning('User suspended', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
                'reason' => $validated['reason']
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuário suspenso',
                'user' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error('Error suspending user', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao suspender usuário'
            ], 500);
        }
    }

    /**
     * Activate (unsuspend) user
     */
    public function activateUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $user->update([
                'is_suspended' => false
            ]);

            Log::info('User activated', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuário reativado',
                'user' => $user->fresh()
            ]);
        } catch (\Exception $e) {
            Log::error('Error activating user', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao reativar usuário'
            ], 500);
        }
    }
}
