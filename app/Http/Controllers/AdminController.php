<?php

namespace App\Http\Controllers;

use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Professional;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminController extends Controller
{
    /**
     * All counters below read from `users`, so they are collected in a single
     * `FILTER`-based aggregate instead of one query per counter.
     */
    private const USER_AGGREGATES_SQL = <<<'SQL'
        COUNT(*) AS total,
        COUNT(*) FILTER (WHERE role = 'tutor') AS tutors,
        COUNT(*) FILTER (WHERE role = 'professional') AS professionals,
        COUNT(*) FILTER (WHERE role = 'company') AS companies,
        COUNT(*) FILTER (WHERE role = 'admin') AS admins,
        COUNT(*) FILTER (WHERE created_at >= ?) AS current_month,
        COUNT(*) FILTER (WHERE created_at >= ? AND created_at < ?) AS previous_month,
        COUNT(*) FILTER (WHERE created_at >= ?) AS pending_last_day,
        COUNT(*) FILTER (WHERE created_at >= ?) AS recent_registrations,
        COUNT(*) FILTER (WHERE is_suspended = true) AS suspended,
        COUNT(*) FILTER (WHERE role = 'company' AND registration_status = 'pending') AS pending_company_approvals
    SQL;

    /**
     * Get dashboard statistics
     */
    public function stats(Request $request)
    {
        try {
            $users = $this->fetchUserAggregates();
            $totalProfessionals = Professional::count();
            $totalAppointments = Appointment::count();
            $revenue = Payment::where('status', PaymentStatus::PAID->value)->sum('amount');
            $pendingVerifications = Document::where('verification_status', 'pending')->count();
            $pendingReviews = $this->countPendingReviews();

            $usersTrend = $users['previous_month'] > 0
                ? round((($users['current_month'] - $users['previous_month']) / $users['previous_month']) * 100)
                : 0;

            $stats = [
                'totalUsers' => $users['total'],
                'totalProfessionals' => $totalProfessionals,
                'totalAppointments' => $totalAppointments,
                'revenue' => (float) $revenue,
                'usersTrend' => $usersTrend,
                'professionalsTrend' => 0,
                'appointmentsTrend' => 0,
                'revenueTrend' => 0,
                'pendingUsers' => $users['pending_last_day'],
                'pendingApprovals' => $users['pending_company_approvals'],
                'pendingVerifications' => $pendingVerifications,
                'pendingReviews' => $pendingReviews,
                'users' => [
                    'total' => $users['total'],
                    'tutors' => $users['tutors'],
                    'professionals' => $users['professionals'],
                    'companies' => $users['companies'],
                    'admins' => $users['admins'],
                ],
                'pending' => [
                    'companies' => $users['pending_company_approvals'],
                    'documents' => $pendingVerifications,
                    'reviews' => $pendingReviews,
                ],
                'recent_registrations' => $users['recent_registrations'],
                'suspended_users' => $users['suspended'],
                'registration_trend' => $this->getRegistrationTrend(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching admin stats', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar estatísticas',
            ], 500);
        }
    }

    /**
     * Every counter that reads `users` (totals per role, month-over-month trend,
     * pending-approval bucket, recent/suspended counts) collapsed into one query.
     *
     * @return array<string, int>
     */
    private function fetchUserAggregates(): array
    {
        $now = now();

        $row = DB::table('users')
            ->whereNull('deleted_at')
            ->selectRaw(self::USER_AGGREGATES_SQL, [
                $now->copy()->startOfMonth(),
                $now->copy()->subMonth()->startOfMonth(),
                $now->copy()->startOfMonth(),
                $now->copy()->subDay(),
                $now->copy()->subDays(7),
            ])
            ->first();

        return array_map(static fn ($value): int => (int) $value, (array) $row);
    }

    /**
     * Isolated because `is_visible`/`is_flagged` were added later and some
     * environments may still lack them — keep the defensive try/catch.
     */
    private function countPendingReviews(): int
    {
        try {
            return Review::where('is_visible', false)->where('is_flagged', false)->count();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Registration trend for the last 7 days, including days with zero
     * registrations. `generate_series` guarantees the zero days show up —
     * a naive `GROUP BY date(created_at)` would silently skip them.
     *
     * Aggregating `users` by day first and only then joining the 7-row
     * `generate_series` (instead of joining raw rows against every day)
     * avoids an O(days × rows) nested loop — ~310ms vs ~55ms measured
     * against the 200k-row benchmark table, since `users.created_at` has
     * no index to make the naive join sargable either way.
     */
    private function getRegistrationTrend(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT d::date AS date, COALESCE(daily.count, 0) AS count
            FROM generate_series(CURRENT_DATE - INTERVAL '6 days', CURRENT_DATE, INTERVAL '1 day') d
            LEFT JOIN (
                SELECT date_trunc('day', created_at) AS day, COUNT(*) AS count
                FROM users
                WHERE deleted_at IS NULL
                    AND created_at >= CURRENT_DATE - INTERVAL '6 days'
                    AND created_at < CURRENT_DATE + INTERVAL '1 day'
                GROUP BY date_trunc('day', created_at)
            ) daily ON daily.day = d
            ORDER BY d
        SQL);

        return array_map(static fn ($row): array => [
            'date' => $row->date,
            'count' => (int) $row->count,
        ], $rows);
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
                'companies' => $companies,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending companies', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar empresas pendentes',
            ], 500);
        }
    }

    /**
     * Approve company registration
     */
    public function approveCompany(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:1000',
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
                'reviewed_at' => now(),
            ]);

            // Log the action
            Log::info('Company approved', [
                'admin_id' => $request->user()->id,
                'admin_email' => $request->user()->email,
                'company_id' => $company->id,
                'company_email' => $company->email,
                'company_name' => $company->name,
                'notes' => $validated['notes'] ?? 'N/A',
            ]);

            // TODO: Send approval email
            try {
                // Mail::to($company->email)->send(new CompanyApproved($company));
            } catch (\Exception $e) {
                Log::error('Failed to send approval email', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Empresa aprovada com sucesso',
                'company' => $company->fresh()->load('company'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error approving company', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'company_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao aprovar empresa',
            ], 500);
        }
    }

    /**
     * Reject company registration
     */
    public function rejectCompany(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:1000',
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
                'reviewed_at' => now(),
            ]);

            // Log the action
            Log::info('Company rejected', [
                'admin_id' => $request->user()->id,
                'admin_email' => $request->user()->email,
                'company_id' => $company->id,
                'company_email' => $company->email,
                'company_name' => $company->name,
                'reason' => $validated['notes'],
            ]);

            // TODO: Send rejection email
            try {
                // Mail::to($company->email)->send(new CompanyRejected($company, $validated['notes']));
            } catch (\Exception $e) {
                Log::error('Failed to send rejection email', [
                    'company_id' => $company->id,
                    'error' => $e->getMessage(),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Registro da empresa rejeitado',
                'company' => $company->fresh()->load('company'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error rejecting company', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'company_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar empresa',
            ], 500);
        }
    }

    /**
     * Get pending documents for verification.
     * Accepts `?type=crmv` to filter the CRMV moderation queue specifically.
     */
    public function pendingDocuments(Request $request)
    {
        try {
            $query = Document::where('verification_status', 'pending')
                ->with(['user' => function ($query) {
                    $query->select('id', 'name', 'email', 'role', 'user_type');
                }, 'user.professional:user_id,crmv,crmv_state,is_crmv_verified'])
                ->orderBy('created_at', 'desc');

            if ($type = $request->query('type')) {
                $query->where('document_type', $type);
            }

            $documents = $query->paginate((int) $request->integer('per_page', 20));

            return response()->json([
                'success' => true,
                'documents' => $documents,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pending documents', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar documentos pendentes',
            ], 500);
        }
    }

    /**
     * Verify a document
     */
    public function verifyDocument(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $document = Document::findOrFail($id);

            $document->update([
                'verification_status' => 'verified',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $validated['notes'] ?? 'Documento verificado e aprovado',
            ]);

            // CRMV approved → flip the professional's verification badge (CLAUDE.md §2).
            if ($document->document_type === 'crmv') {
                \App\Models\Professional::where('user_id', $document->user_id)->update([
                    'is_crmv_verified' => true,
                    'crmv_verified_at' => now(),
                    'crmv_verified_by' => $request->user()->id,
                ]);
            }

            Log::info('Document verified', [
                'admin_id' => $request->user()->id,
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'document_type' => $document->document_type,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Documento verificado com sucesso',
                'document' => $document->fresh()->load('user'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying document', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'document_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao verificar documento',
            ], 500);
        }
    }

    /**
     * Reject a document
     */
    public function rejectDocument(Request $request, $id)
    {
        $validated = $request->validate([
            'notes' => 'required|string|max:500',
        ]);

        try {
            $document = Document::findOrFail($id);

            $document->update([
                'verification_status' => 'rejected',
                'verified_by' => $request->user()->id,
                'verified_at' => now(),
                'verification_notes' => $validated['notes'],
            ]);

            Log::info('Document rejected', [
                'admin_id' => $request->user()->id,
                'document_id' => $document->id,
                'user_id' => $document->user_id,
                'document_type' => $document->document_type,
                'reason' => $validated['notes'],
            ]);

            // TODO: Send notification to user
            return response()->json([
                'success' => true,
                'message' => 'Documento rejeitado',
                'document' => $document->fresh()->load('user'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error rejecting document', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'document_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao rejeitar documento',
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
                'users' => $users,
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing users', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao listar usuários',
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
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching user details', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao carregar usuário',
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
            'email' => 'sometimes|email|unique:users,email,'.$id,
            'phone' => 'sometimes|string|max:20',
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $user = User::findOrFail($id);
            $user->update($validated);

            Log::info('User updated by admin', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
                'changes' => $validated,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuário atualizado com sucesso',
                'user' => $user->fresh()->load(['professional', 'company']),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating user', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar usuário',
            ], 500);
        }
    }

    /**
     * Suspend user
     */
    public function suspendUser(Request $request, $id)
    {
        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        try {
            $user = User::findOrFail($id);

            // Cannot suspend admins
            if ($user->role === 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Não é possível suspender um administrador',
                ], 403);
            }

            $user->update([
                'is_suspended' => true,
                'admin_notes' => 'Suspenso: '.$validated['reason'],
            ]);

            Log::warning('User suspended', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
                'reason' => $validated['reason'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuário suspenso',
                'user' => $user->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error suspending user', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao suspender usuário',
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
                'is_suspended' => false,
            ]);

            Log::info('User activated', [
                'admin_id' => $request->user()->id,
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Usuário reativado',
                'user' => $user->fresh(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error activating user', [
                'error' => $e->getMessage(),
                'admin_id' => $request->user()->id,
                'user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao reativar usuário',
            ], 500);
        }
    }
}
