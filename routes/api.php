<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AiBusinessController;
use App\Http\Controllers\Api\AiBusinessInsightsController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\BreedController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\HospitalizationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LgpdController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PetCardController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\PetHealthRecordsController;
use App\Http\Controllers\Api\PetHealthSummaryController;
use App\Http\Controllers\Api\PetVetAccessController;
use App\Http\Controllers\Api\PetWeightController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ProfessionalClientController;
use App\Http\Controllers\Api\ProfessionalDashboardController;
use App\Http\Controllers\Api\Public\BookingController;
use App\Http\Controllers\Api\Public\MasterDataController;
use App\Http\Controllers\Api\Public\PetCardController as PublicPetCardController;
use App\Http\Controllers\Api\Public\ProfessionalController;
use App\Http\Controllers\Api\Public\SearchController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SurgeryController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VaccinationController;
use App\Http\Controllers\Api\VideoConsultationController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\ProfessionalSchemaController;
use App\Http\Controllers\RegistrationCompletionController;
use App\Http\Controllers\RegistrationDraftController;
use App\Http\Middleware\AdminMiddleware;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------
// Webhooks — no auth, no CSRF
// ---------------------------------------------------------------
Route::prefix('webhooks')->group(function () {
    Route::post('/stripe', [WebhookController::class, 'stripe']);
    Route::post('/mercadopago', [WebhookController::class, 'mercadopago']);
});

// ---------------------------------------------------------------
// Public routes — rate limited (60/min for auth, 30/min for search)
// ---------------------------------------------------------------

// Auth routes — throttled to prevent brute force
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);

    // Reativação de conta autodesativada — público porque quem chega aqui está bloqueado
    // do /login normal (sem token Sanctum). Mesmas credenciais do login.
    Route::post('/account/reactivate', [AccountController::class, 'reactivate']);
});

// Token de 64 caracteres não é força-bruteável em 10 tentativas/min, mas o endpoint não tinha
// NENHUM throttle antes (achado da auditoria de cadastro, P2) — defesa em profundidade, mesmo
// padrão dos outros endpoints públicos de auth abaixo.
Route::post('/verify-email/{token}', [\App\Http\Controllers\EmailVerificationController::class, 'verify'])
    ->middleware('throttle:10,1');
Route::post('/resend-verification', [\App\Http\Controllers\EmailVerificationController::class, 'resend'])
    ->middleware('throttle:5,1');

// Organization invitations — public view of a token, before the invited person logs in.
// Accept requires auth (see the authenticated block below) since it creates the membership
// under the calling user's own account.
Route::get('/organization-invitations/{token}', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'show'])
    ->middleware('throttle:30,1');

// Public Search & Discovery — throttled (30 requests/min)
// Feature flags — public read-only map for frontend to hide UI of disabled features
Route::get('/features', function () {
    return response()->json(config('features'));
});

Route::prefix('public')->middleware('throttle:30,1')->group(function () {
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/nearby', [SearchController::class, 'nearby']);
    Route::get('/categories', [SearchController::class, 'categories']);
    Route::get('/featured', [SearchController::class, 'featured']);
    Route::get('/professionals/{id}', [ProfessionalController::class, 'show']);
    Route::get('/pet-card/{publicId}', [PublicPetCardController::class, 'show']);
    Route::get('/breeds', [BreedController::class, 'index']);

    // Master data endpoints
    Route::get('/pathologies', [MasterDataController::class, 'pathologies']);
    Route::get('/vaccine-catalog', [MasterDataController::class, 'vaccineCatalog']);
    Route::get('/food-brands', [MasterDataController::class, 'foodBrands']);
    Route::get('/specialties', [MasterDataController::class, 'specialties']);
    Route::get('/food-allergies', [MasterDataController::class, 'foodAllergies']);
    Route::get('/dietary-restrictions', [MasterDataController::class, 'dietaryRestrictions']);
});

// Signed document file access (CRMV/RG/diploma preview in the admin panel).
// No `auth:sanctum` on purpose: an `<img src>`/direct link can't carry a bearer
// token. The short-lived signature — minted only for authorized viewers by
// DocumentResource::documentUrl() — is the access control instead.
Route::get('/documents/{document}/file', [DocumentFileController::class, 'show'])
    ->name('documents.file')
    ->middleware(['signed', 'throttle:60,1']);

// Public booking routes (require auth)
Route::prefix('public')->middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::get('/booking/availability', [BookingController::class, 'availability']);
    Route::post('/booking', [BookingController::class, 'book']);
    Route::post('/booking/{id}/cancel', [BookingController::class, 'cancel']);
    Route::post('/booking/{id}/reschedule', [BookingController::class, 'reschedule']);
    Route::post('/waitlist', [BookingController::class, 'joinWaitlist']);
});

// ---------------------------------------------------------------
// Protected routes — auth required, 60 requests/min
// ---------------------------------------------------------------
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Registration Completion
    Route::post('/register/complete-tutor', [RegistrationCompletionController::class, 'completeTutor']);
    Route::post('/register/complete-professional', [RegistrationCompletionController::class, 'completeProfessional']);
    Route::post('/register/complete-company', [RegistrationCompletionController::class, 'completeCompany']);
    Route::get('/register/professional-schema', ProfessionalSchemaController::class)->middleware('locale');

    // Registration Draft Auto-Save
    Route::post('/register/draft/professional', [RegistrationDraftController::class, 'saveProfessionalDraft']);
    Route::get('/register/draft/professional', [RegistrationDraftController::class, 'loadProfessionalDraft']);
    Route::delete('/register/draft/professional', [RegistrationDraftController::class, 'deleteProfessionalDraft']);

    Route::post('/register/draft/company', [RegistrationDraftController::class, 'saveCompanyDraft']);
    Route::get('/register/draft/company', [RegistrationDraftController::class, 'loadCompanyDraft']);
    Route::delete('/register/draft/company', [RegistrationDraftController::class, 'deleteCompanyDraft']);

    Route::post('/register/draft/tutor', [RegistrationDraftController::class, 'saveTutorDraft']);
    Route::get('/register/draft/tutor', [RegistrationDraftController::class, 'loadTutorDraft']);
    Route::delete('/register/draft/tutor', [RegistrationDraftController::class, 'deleteTutorDraft']);

    // Documents
    Route::post('/documents/upload', [DocumentController::class, 'upload']);
    Route::delete('/documents/{id}', [DocumentController::class, 'destroy']);

    // User profile routes
    Route::get('/profile', [UserController::class, 'profile']);
    Route::put('/profile', [UserController::class, 'updateProfile']);
    // Foto de perfil: POST (nao PUT) porque o corpo e multipart — o PHP nao faz
    // o parse de multipart em PUT e o arquivo chegaria vazio.
    Route::post('/profile/avatar', [UserController::class, 'updateAvatar']);
    Route::delete('/profile/avatar', [UserController::class, 'destroyAvatar']);

    // Dashboard stats for tutor
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Pet routes — literal segments must be declared before apiResource so
    // `/pets/search` and `/pets/health-summary` don't get captured by the
    // `/pets/{pet}` show route.
    Route::get('/pets/search', [PetController::class, 'search']);

    // Aggregated health roll-up of every pet of the authenticated tutor — replaces
    // the 2xN per-pet requests the health dashboard used to fire.
    Route::get('/pets/health-summary', PetHealthSummaryController::class);
    Route::apiResource('pets', PetController::class);

    // Tutor-facing health records nested under a pet.
    // Type segment accepts: vaccinations, dewormings, medications, surgeries, exams.
    Route::prefix('pets/{pet}/health')->group(function () {
        Route::get('{type}', [PetHealthRecordsController::class, 'index'])
            ->where('type', 'vaccinations|dewormings|medications|surgeries|exams');
        Route::post('{type}', [PetHealthRecordsController::class, 'store'])
            ->where('type', 'vaccinations|dewormings|medications|surgeries|exams');
        Route::put('{type}/{record}', [PetHealthRecordsController::class, 'update'])
            ->where('type', 'vaccinations|dewormings|medications|surgeries|exams');
        Route::delete('{type}/{record}', [PetHealthRecordsController::class, 'destroy'])
            ->where('type', 'vaccinations|dewormings|medications|surgeries|exams');

        // Semantic end-of-treatment: keeps the medication in the history but marks it inactive.
        Route::patch('medications/{record}/deactivate', [PetHealthRecordsController::class, 'deactivateMedication']);
    });

    // Consolidated audit timeline for a pet (pet row + clinical sub-resources).
    Route::get('/pets/{pet}/audit', [\App\Http\Controllers\Api\PetAuditController::class, 'index']);

    // Pet weight history — tutor + any active vet grant can read; owner + WRITE/FULL can add.
    Route::get('/pets/{pet}/weights', [PetWeightController::class, 'index']);
    Route::post('/pets/{pet}/weights', [PetWeightController::class, 'store']);

    // Pet Vet Access — controle de acesso veterinario ao pet
    Route::prefix('pet-vet-access')->group(function () {
        // Tutor → concede acesso diretamente (fluxo antigo, pet já existe).
        Route::post('/grant', [PetVetAccessController::class, 'grant']);

        // Vet → solicita acesso por pet_id OU por CPF do tutor (cria pet em nome do tutor se necessário).
        Route::post('/request', [PetVetAccessController::class, 'requestAccess']);

        // Tutor → responde solicitações pendentes.
        Route::get('/pending', [PetVetAccessController::class, 'pendingForTutor']);
        Route::post('/{accessId}/accept', [PetVetAccessController::class, 'accept']);
        Route::post('/{accessId}/reject', [PetVetAccessController::class, 'reject']);

        // Tutor → altera o nível de um acesso já aceito (sobe ou desce), sem nova solicitação.
        Route::patch('/{accessId}/level', [PetVetAccessController::class, 'changeLevel']);

        // Tutor → revoga acesso aceito.
        Route::post('/{accessId}/revoke', [PetVetAccessController::class, 'revoke']);

        // Listagens.
        Route::get('/my-accesses', [PetVetAccessController::class, 'myAccesses']);
        Route::get('/pet/{petId}', [PetVetAccessController::class, 'petAccesses']);
    });

    // Organization member management — só o owner ativo da organização gerencia
    // (App\Policies\OrganizationPolicy::manageMembers). Member/invitation escopados à
    // organização da URL dentro do controller (App\Http\Controllers\Concerns\ResolvesOrganizationChildren).
    Route::prefix('organizations/{organization}')->group(function () {
        Route::get('/members', [\App\Http\Controllers\Api\OrganizationMemberController::class, 'index']);
        Route::patch('/members/{member}', [\App\Http\Controllers\Api\OrganizationMemberController::class, 'update']);
        Route::delete('/members/{member}', [\App\Http\Controllers\Api\OrganizationMemberController::class, 'destroy']);

        Route::post('/invitations', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'store']);
        Route::get('/invitations', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'index']);
        Route::delete('/invitations/{invitation}', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'destroy']);
    });

    // Accept requires auth: the membership is created under the calling user's own account.
    Route::post('/organization-invitations/{token}/accept', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'accept']);

    // Tutor Appointments
    Route::get('/appointments', function (Request $request) {
        $query = Appointment::with(['professional.professional', 'pet', 'service'])
            ->where('client_id', $request->user()->id);

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $appointments = $query->orderBy('appointment_date', 'desc')->paginate(20);

        return \App\Http\Resources\AppointmentResource::collection($appointments);
    });
    Route::get('/appointments/{id}', function (Request $request, $id) {
        $appointment = Appointment::with(['professional.professional', 'pet', 'service'])
            ->where('client_id', $request->user()->id)
            ->findOrFail($id);

        return response()->json(['data' => new \App\Http\Resources\AppointmentResource($appointment)]);
    });
    Route::post('/appointments/{id}/cancel', function (Request $request, $id) {
        $request->validate(['reason' => 'nullable|string|max:500']);
        $appointment = Appointment::where('client_id', $request->user()->id)->findOrFail($id);
        $bookingService = app(\App\Services\Booking\BookingService::class);
        $cancelled = $bookingService->cancelBooking(
            $appointment->id,
            $request->input('reason') ?? 'Cancelado pelo tutor'
        );

        return response()->json([
            'message' => 'Appointment cancelled successfully',
            'data' => new \App\Http\Resources\AppointmentResource($cancelled->load(['professional.professional', 'pet', 'service'])),
        ]);
    });

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index']);
    Route::post('/favorites/{professionalId}', [FavoriteController::class, 'toggle']);
    Route::get('/favorites/check/{professionalId}', [FavoriteController::class, 'check']);

    // Professional/Medical routes
    Route::prefix('professional')->group(function () {
        // Appointments
        Route::get('appointments/today', [AppointmentController::class, 'today']);
        Route::get('appointments/upcoming', [AppointmentController::class, 'upcoming']);
        Route::apiResource('appointments', AppointmentController::class);

        // Medical Records
        Route::apiResource('medical-records', MedicalRecordController::class);

        // Vaccinations
        Route::get('vaccinations/upcoming', [VaccinationController::class, 'upcoming']);
        Route::apiResource('vaccinations', VaccinationController::class);

        // Prescriptions
        Route::get('prescriptions/valid', [PrescriptionController::class, 'valid']);
        Route::apiResource('prescriptions', PrescriptionController::class);

        // Hospitalizations
        Route::apiResource('hospitalizations', HospitalizationController::class);

        // Surgeries
        Route::apiResource('surgeries', SurgeryController::class);

        // Invoices
        Route::apiResource('invoices', InvoiceController::class);

        // Services
        Route::apiResource('services', ServiceController::class);

        // Inventory
        Route::apiResource('inventory', InventoryController::class);

        // My patients — pets this vet has active PetVetAccess grants for (enriched list).
        Route::get('my-patients', [PetVetAccessController::class, 'myPatients']);

        // Clients
        Route::apiResource('clients', ProfessionalClientController::class);
        Route::get('clients/{id}/pets', [ProfessionalClientController::class, 'pets']);

        // Professional Dashboard Stats
        Route::get('dashboard/stats', [ProfessionalDashboardController::class, 'stats']);
    });

    // AI Guardian Route (V3 — gated)
    Route::post('/ai/analyze', [AiController::class, 'analyze'])->middleware('feature:ai_guardian');

    // AI Business Insight Route (V3 — gated)
    Route::post('/ai/business-analyze', [AiBusinessController::class, 'analyze'])->middleware('feature:ai_business');

    // AI Business Insights Dashboard (V3 — gated)
    Route::get('/professional/ai/insights', [AiBusinessInsightsController::class, 'generateInsights'])->middleware('feature:ai_business');

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread', [NotificationController::class, 'unread']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::get('/preferences', [NotificationController::class, 'getPreferences']);
        Route::put('/preferences', [NotificationController::class, 'updatePreferences']);
        Route::post('/devices/register', [NotificationController::class, 'registerDevice']);
        Route::post('/devices/unregister', [NotificationController::class, 'unregisterDevice']);
    });

    // Payments
    Route::prefix('payments')->group(function () {
        Route::post('/', [PaymentController::class, 'create']);
        Route::post('/validate-coupon', [PaymentController::class, 'validateCoupon']);
        Route::get('/{id}', [PaymentController::class, 'show']);
        Route::post('/{id}/refund', [PaymentController::class, 'refund']);
    });

    // Messages
    Route::prefix('messages')->group(function () {
        Route::get('/conversations', [MessageController::class, 'conversations']);
        Route::get('/conversations/{id}', [MessageController::class, 'show']);
        Route::post('/send', [MessageController::class, 'send']);
        Route::post('/conversations/{id}/read', [MessageController::class, 'markAsRead']);
        Route::get('/unread-count', [MessageController::class, 'unreadCount']);
    });

    // Reviews & Ratings
    Route::prefix('reviews')->group(function () {
        Route::get('/professional/{professionalId}', [ReviewController::class, 'index']);
        Route::post('/', [ReviewController::class, 'store']);
        Route::post('/{id}/response', [ReviewController::class, 'addResponse']);
        Route::post('/{id}/flag', [ReviewController::class, 'flag']);
        Route::post('/{id}/helpful', [ReviewController::class, 'toggleHelpful']);

        // Admin moderation queue — pre-moderation: reviews stay hidden until approved.
        Route::get('/pending', [ReviewController::class, 'pending'])->middleware(AdminMiddleware::class);
        Route::post('/{id}/moderate', [ReviewController::class, 'moderate'])->middleware(AdminMiddleware::class);
    });

    // Health Reminders
    Route::prefix('reminders')->group(function () {
        Route::get('/', [ReminderController::class, 'index']);
        Route::get('/pending', [ReminderController::class, 'pending']);
        Route::post('/{id}/snooze', [ReminderController::class, 'snooze']);
        Route::post('/{id}/dismiss', [ReminderController::class, 'dismiss']);
        Route::post('/{id}/complete', [ReminderController::class, 'complete']);
        Route::get('/preferences', [ReminderController::class, 'getPreferences']);
        Route::put('/preferences', [ReminderController::class, 'updatePreferences']);
    });

    // Pet Cards
    Route::prefix('pet-card')->group(function () {
        Route::get('/{petId}/qr-code', [PetCardController::class, 'getQRCode']);
        Route::post('/{petId}/mark-lost', [PetCardController::class, 'markLost']);
        Route::post('/{petId}/mark-found', [PetCardController::class, 'markFound']);
    });

    // Reports & PDF Downloads
    Route::prefix('reports')->group(function () {
        Route::get('/invoice/{invoiceId}/pdf', [ReportController::class, 'downloadInvoice']);
        Route::get('/prescription/{prescriptionId}/pdf', [ReportController::class, 'downloadPrescription']);
        Route::get('/medical-history/{petId}/pdf', [ReportController::class, 'downloadMedicalHistory']);
        Route::get('/revenue', [ReportController::class, 'getRevenueReport']);
    });

    // Lab Exams & Results
    Route::prefix('exams')->group(function () {
        Route::get('/pet/{petId}', [ExamController::class, 'index']);
        Route::post('/', [ExamController::class, 'store']);
        Route::post('/{examId}/results', [ExamController::class, 'addResults']);
        Route::post('/{examId}/images', [ExamController::class, 'addImages']);
        // Attachments of an exam. Download returns a signed URL (S3) or streams (local).
        // Delete is tutor-only — same rule as clinical soft-delete in PetHealthRecordsController.
        Route::get('/images/{imageId}/download', [ExamController::class, 'downloadImage']);
        Route::delete('/images/{imageId}', [ExamController::class, 'destroyImage']);
        Route::get('/pet/{petId}/history/{parameter}', [ExamController::class, 'getHistory']);
    });

    // Subscriptions
    Route::prefix('subscriptions')->group(function () {
        Route::get('/plans', [SubscriptionController::class, 'plans']);
        Route::get('/current', [SubscriptionController::class, 'current']);
        Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
        Route::post('/upgrade', [SubscriptionController::class, 'upgrade']);
        Route::post('/cancel', [SubscriptionController::class, 'cancel']);
        Route::get('/check-feature/{feature}', [SubscriptionController::class, 'checkFeature']);
        Route::get('/check-usage/{feature}', [SubscriptionController::class, 'checkUsage']);
    });

    // LGPD / Privacy
    Route::prefix('lgpd')->group(function () {
        Route::get('/export', [LgpdController::class, 'exportData']);
        Route::post('/delete-account', [LgpdController::class, 'deleteAccount']);
        Route::get('/consent', [LgpdController::class, 'consentStatus']);
        Route::put('/consent', [LgpdController::class, 'updateConsent']);
    });

    // Desativação voluntária da própria conta (não confundir com o /lgpd/delete-account
    // acima — aqui nada é apagado ou anonimizado, ver AccountDeactivationService).
    Route::post('/account/deactivate', [AccountController::class, 'deactivate']);

    // Video Consultations (V3 — gated)
    Route::prefix('video-consultations')->middleware('feature:video_consultations')->group(function () {
        Route::post('/', [VideoConsultationController::class, 'create']);
        Route::post('/{id}/join', [VideoConsultationController::class, 'join']);
        Route::post('/{id}/start', [VideoConsultationController::class, 'start']);
        Route::post('/{id}/end', [VideoConsultationController::class, 'end']);
        Route::post('/recordings/{recordingId}/consent', [VideoConsultationController::class, 'grantRecordingConsent']);
        Route::delete('/recordings/{recordingId}/consent', [VideoConsultationController::class, 'revokeRecordingConsent']);
    });

    // Admin Routes
    Route::prefix('admin')->middleware(AdminMiddleware::class)->group(function () {
        // Dashboard
        Route::get('/dashboard/stats', [AdminController::class, 'stats']);

        // Company Management
        Route::get('/companies/pending', [AdminController::class, 'pendingCompanies']);
        Route::post('/companies/{id}/approve', [AdminController::class, 'approveCompany']);
        Route::post('/companies/{id}/reject', [AdminController::class, 'rejectCompany']);

        // Document Verification
        Route::get('/documents/pending', [AdminController::class, 'pendingDocuments']);
        Route::post('/documents/{id}/verify', [AdminController::class, 'verifyDocument']);
        Route::post('/documents/{id}/reject', [AdminController::class, 'rejectDocument']);

        // User Management
        Route::get('/users', [AdminController::class, 'listUsers']);
        Route::get('/users/{id}', [AdminController::class, 'showUser']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);
        Route::post('/users/{id}/suspend', [AdminController::class, 'suspendUser']);
        Route::post('/users/{id}/activate', [AdminController::class, 'activateUser']);
        Route::post('/users/{id}/deactivate', [AdminController::class, 'deactivateUser']);
        Route::post('/users/{id}/reactivate', [AdminController::class, 'reactivateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
    });
});
