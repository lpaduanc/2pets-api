<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AiBusinessController;
use App\Http\Controllers\Api\AiBusinessInsightsController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AppointmentChargeController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\BreedController;
use App\Http\Controllers\Api\Commercial\BrandController;
use App\Http\Controllers\Api\Commercial\PriceListController;
use App\Http\Controllers\Api\Commercial\ProductController;
use App\Http\Controllers\Api\Commercial\ProductGroupController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\HospitalizationCareLogController;
use App\Http\Controllers\Api\HospitalizationClinicalActController;
use App\Http\Controllers\Api\HospitalizationController;
use App\Http\Controllers\Api\HospitalizationProgressNoteController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LgpdController;
use App\Http\Controllers\Api\MarketingUnsubscribeController;
use App\Http\Controllers\Api\MedicalRecordAttachmentController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\MedicalRecordPetDataController;
use App\Http\Controllers\Api\MedicalRecordReadController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NewPatientAppointmentController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PetCardController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\PetHealthRecordsController;
use App\Http\Controllers\Api\PetHealthSummaryController;
use App\Http\Controllers\Api\PetInvoicesController;
use App\Http\Controllers\Api\PetTimelineController;
use App\Http\Controllers\Api\PetVetAccessController;
use App\Http\Controllers\Api\PetWeightController;
use App\Http\Controllers\Api\PrescriptionAlertsController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\PrescriptionItemPromotionController;
use App\Http\Controllers\Api\PrescriptionReadController;
use App\Http\Controllers\Api\ProfessionalClientController;
use App\Http\Controllers\Api\ProfessionalDashboardController;
use App\Http\Controllers\Api\Public\BookingController;
use App\Http\Controllers\Api\Public\MasterDataController;
use App\Http\Controllers\Api\Public\PetCardController as PublicPetCardController;
use App\Http\Controllers\Api\Public\ProfessionalController;
use App\Http\Controllers\Api\Public\ReverseGeocodeController;
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
use App\Http\Controllers\RegistrationContinuationController;
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

    // Link de continuação de cadastro (contrato docs/atendimento-veterinario/
    // 07-contrato-agendamento-pet-novo.md §6) — público, o tutor ainda não tem sessão.
    // GET mostra nome/CPF/pet sem consumir o token; POST define a senha e consome.
    Route::get('/register/continue/{token}', [RegistrationContinuationController::class, 'show']);
    Route::post('/register/continue/{token}', [RegistrationContinuationController::class, 'complete']);
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

// Busca e descoberta ficam FORA do grupo de 30/min abaixo, no limitador nomeado
// `public-search` (60/min por IP; o número e o porquê estão em
// `AppServiceProvider::registerRateLimiters()`). Precisa ser um grupo separado, e não um
// `throttle:` aninhado: middleware de grupo soma, então herdar os 30/min do grupo de baixo
// manteria o teto antigo valendo e o limite novo não teria efeito nenhum.
Route::prefix('public')->middleware('throttle:public-search')->group(function () {
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/nearby', [SearchController::class, 'nearby']);
    Route::get('/categories', [SearchController::class, 'categories']);
    Route::get('/featured', [SearchController::class, 'featured']);
});

// Reverse geocoding — grupo PRÓPRIO, e não dentro de um dos grupos acima. Middleware de
// grupo SOMA: aninhar `throttle:reverse-geocode` num grupo que já tem `throttle:30,1`
// manteria o teto de 30 valendo em paralelo, e o limite apertado (10/min) não teria efeito.
// Mesma armadilha documentada no grupo da busca.
Route::prefix('public')->middleware('throttle:reverse-geocode')->group(function () {
    Route::get('/reverse-geocode', ReverseGeocodeController::class);
});

Route::prefix('public')->middleware('throttle:30,1')->group(function () {
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

// Lista curada de alertas clínicos do receituário — contrato
// docs/atendimento-veterinario/03-contrato-receituario.md §5. Caminho literal do contrato
// (`reference/prescription-alerts`), não `public/…`: sem PII, cacheável, mesma régua de
// throttle do grupo de master data acima.
Route::prefix('reference')->middleware('throttle:30,1')->group(function () {
    Route::get('/prescription-alerts', [PrescriptionAlertsController::class, 'index']);
});

// Signed document file access (CRMV/RG/diploma preview in the admin panel).
// No `auth:sanctum` on purpose: an `<img src>`/direct link can't carry a bearer
// token. The short-lived signature — minted only for authorized viewers by
// DocumentResource::documentUrl() — is the access control instead.
Route::get('/documents/{document}/file', [DocumentFileController::class, 'show'])
    ->name('documents.file')
    ->middleware(['signed', 'throttle:60,1']);

// Descadastro de marketing em um clique (contrato docs/atendimento-veterinario/
// 07-contrato-agendamento-pet-novo.md §7) — link de e-mail, sem sessão logada.
Route::get('/unsubscribe/marketing/{user}', [MarketingUnsubscribeController::class, 'unsubscribe'])
    ->name('unsubscribe.marketing')
    ->middleware(['signed', 'throttle:30,1']);

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

    // Fluxo de atendimento — leitura compartilhada tutor + vet autorizado (contrato
    // docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md §1 "Leitura"). Fora do
    // prefixo `professional`: quem lê aqui pode ser o dono do pet.
    Route::get('/pets/{pet}/medical-records', [MedicalRecordReadController::class, 'forPet']);
    Route::get('/medical-records/{id}', [MedicalRecordReadController::class, 'show']);

    // Promover consulta → cadastro do pet — SEMPRE ação do TUTOR (contrato
    // docs/atendimento-veterinario/08-consulta-autorizada-por-agendamento.md §D), por isso
    // fora do prefixo `professional/`. Segmento literal antes de `/medical-records/{id}` não
    // é necessário aqui: os dois convivem porque `pet-data-diff`/`apply-to-pet` são sufixos,
    // não o próprio `{id}`.
    Route::get('/medical-records/{id}/pet-data-diff', [MedicalRecordPetDataController::class, 'diff']);
    Route::post('/medical-records/{id}/apply-to-pet', [MedicalRecordPetDataController::class, 'apply']);

    // Faturamento — leitura do tutor (contrato docs/atendimento-veterinario/
    // 09-faturamento-do-atendimento.md §4 "Leitura (tutor)"). Fora do prefixo `professional/`
    // de propósito: quem lê aqui é o `client_id` da fatura, não o profissional. `GET
    // invoices/{id}` reusa o MESMO `InvoiceController::show` da rota profissional —
    // `InvoicePolicy::view` decide as duas audiências.
    Route::get('/pets/{pet}/invoices', PetInvoicesController::class);
    Route::get('/invoices/{id}', [InvoiceController::class, 'show']);

    // Leitura compartilhada de prescrição — contrato
    // docs/atendimento-veterinario/03-contrato-receituario.md §1/§6. Registrada pelo
    // frontend-specialist; mesmo espírito das duas rotas acima.
    Route::get('/pets/{pet}/prescriptions', [PrescriptionReadController::class, 'forPet']);
    Route::get('/pets/{pet}/timeline', PetTimelineController::class);

    // Promover item de prescrição a `PetMedication` — SEMPRE ação explícita do tutor (doc de
    // domínio docs/atendimento-veterinario/02-receituario-dominio.md §5.2), por isso fora do
    // prefixo `professional/`.
    Route::post(
        '/prescriptions/{prescriptionId}/items/{itemId}/promote-to-medication',
        PrescriptionItemPromotionController::class
    );

    // Download de anexo de prontuário — mesma regra de acesso do detalhe (autor, tutor ou
    // vet com PetVetAccess). Não é URL pública: exige Sanctum, disco sempre privado.
    Route::get('/medical-records/{id}/attachments/{attachmentId}/download', [MedicalRecordAttachmentController::class, 'download']);

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

        // Paciente novo (contrato docs/atendimento-veterinario/
        // 07-contrato-agendamento-pet-novo.md §1) — endpoint separado, não mistura as regras
        // de `client_id`/`pet_id` obrigatórios do `store()` abaixo.
        Route::post('appointments/new-patient', [NewPatientAppointmentController::class, 'store']);

        // Fluxo de atendimento (contrato docs/atendimento-veterinario/01-contrato-api-e-taxonomia.md
        // §1) — segmentos literais ANTES do apiResource, senão `{appointment}` capturaria
        // "walk-in" como id (mesma armadilha já documentada para as rotas de pet acima).
        Route::post('appointments/walk-in', [ConsultationController::class, 'walkIn']);
        Route::post('appointments/{id}/start', [ConsultationController::class, 'start']);
        Route::post('appointments/{id}/close-without-finalizing', [ConsultationController::class, 'closeWithoutFinalizing']);

        // Linhas de cobrança do atendimento — contrato docs/atendimento-veterinario/
        // 09-faturamento-do-atendimento.md §13.3/§13.7. MOVEU de
        // `medical-records/{id}/charges` (§13.3): banho e tosa não gera prontuário, então
        // a conta pendura no agendamento, que todo atendimento tem. Trava de
        // mutabilidade em `AppointmentChargeService`, não aqui.
        Route::get('appointments/{id}/charges', [AppointmentChargeController::class, 'index']);
        Route::post('appointments/{id}/charges', [AppointmentChargeController::class, 'store']);
        Route::put('appointments/{id}/charges/{chargeId}', [AppointmentChargeController::class, 'update']);
        Route::delete('appointments/{id}/charges/{chargeId}', [AppointmentChargeController::class, 'destroy']);

        Route::apiResource('appointments', AppointmentController::class);

        // Medical Records — mesma armadilha: "pending" antes do apiResource, senão vira
        // `{medical_record}` na rota de show.
        Route::get('medical-records/pending', [ConsultationController::class, 'pending']);
        Route::post('medical-records/{id}/summary-preview', [ConsultationController::class, 'summaryPreview']);
        Route::post('medical-records/{id}/finalize', [ConsultationController::class, 'finalize']);
        Route::post('medical-records/{id}/addenda', [ConsultationController::class, 'storeAddendum']);
        Route::post('medical-records/{id}/attachments', [MedicalRecordAttachmentController::class, 'store']);
        Route::delete('medical-records/{id}/attachments/{attachmentId}', [MedicalRecordAttachmentController::class, 'destroy']);

        Route::apiResource('medical-records', MedicalRecordController::class);

        // Vaccinations
        Route::get('vaccinations/upcoming', [VaccinationController::class, 'upcoming']);
        Route::apiResource('vaccinations', VaccinationController::class);

        // Prescriptions — segmentos literais ANTES do apiResource (mesma armadilha já
        // documentada para "pending"/"walk-in" acima).
        Route::get('prescriptions/valid', [PrescriptionController::class, 'valid']);
        Route::post('prescriptions/{id}/issue', [PrescriptionController::class, 'issue']);
        Route::post('prescriptions/{id}/cancel', [PrescriptionController::class, 'cancel']);
        Route::apiResource('prescriptions', PrescriptionController::class);

        // Hospitalizations
        Route::apiResource('hospitalizations', HospitalizationController::class);

        // Ato clínico Grupo A (ex.: cirurgia) aberto DURANTE uma internação ativa —
        // contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md
        // §2.2. Pendura no `appointment_id` da própria internação, cobra na mesma
        // conta; edição/finalização do prontuário resultante reaproveita as rotas de
        // `medical-records/{id}` que já existem acima, sem endpoint novo para isso.
        Route::post('hospitalizations/{id}/clinical-acts', [HospitalizationClinicalActController::class, 'store']);

        // Módulo clínico de internação — contrato docs/atendimento-veterinario/
        // 12-modulo-clinico-internacao.md §1/§4/§7. Sem GET próprio: a leitura é
        // `GET hospitalizations/{id}` com `progress_notes`/`care_logs` eager-loaded (mesmo
        // padrão de `medical-records/{id}/addenda`, sem endpoint de listagem próprio).
        Route::post('hospitalizations/{id}/progress-notes', [HospitalizationProgressNoteController::class, 'store']);
        Route::post('hospitalizations/{id}/care-logs', [HospitalizationCareLogController::class, 'store']);

        // Surgeries
        Route::apiResource('surgeries', SurgeryController::class);

        // Invoices — ciclo de vida (contrato docs/atendimento-veterinario/
        // 09-faturamento-do-atendimento.md §4). Segmentos literais ANTES do apiResource,
        // mesma armadilha já documentada para "pending"/"walk-in" acima.
        Route::post('invoices/{id}/issue', [InvoiceController::class, 'issue']);
        Route::post('invoices/{id}/mark-as-paid', [InvoiceController::class, 'markAsPaid']);
        // Contrato docs/atendimento-veterinario/11-internacao-no-fluxo-de-faturamento.md
        // §3-bis.4 — pagamento adiantado, restrito à internação (`AdvancePaymentService`).
        Route::post('invoices/{id}/advance-payment', [InvoiceController::class, 'recordAdvancePayment']);
        Route::post('invoices/{id}/cancel', [InvoiceController::class, 'cancel']);
        Route::apiResource('invoices', InvoiceController::class);

        // Services
        Route::apiResource('services', ServiceController::class);

        // Inventory
        Route::apiResource('inventory', InventoryController::class);

        // ---------------------------------------------------------------
        // Catálogo comercial — contrato docs/gap-simplesvet/08-produtos-
        // precificacao-lista-precos.md. Escopo por organização em
        // `CommercialScopeResolver`, nunca por `professional_id` solto.
        // ---------------------------------------------------------------

        // Segmento literal ANTES do apiResource: sem isto `{product}` capturaria
        // "lookup" como id — mesma armadilha já documentada em "pending"/"walk-in".
        Route::get('products/lookup', [ProductController::class, 'lookup']);
        Route::apiResource('products', ProductController::class);

        Route::apiResource('product-groups', ProductGroupController::class)->except(['show']);
        Route::apiResource('brands', BrandController::class)->except(['show']);

        // Lista de preços do balcão — `export` antes de qualquer rota com parâmetro.
        Route::get('price-list/export', [PriceListController::class, 'export']);
        Route::get('price-list', [PriceListController::class, 'index']);

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
