<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AiBusinessController;
use App\Http\Controllers\Api\AiBusinessInsightsController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AppointmentChargeController;
use App\Http\Controllers\Api\AppointmentConfirmationController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\BreedController;
use App\Http\Controllers\Api\Commercial\AcquirerSettlementController;
use App\Http\Controllers\Api\Commercial\BrandController;
use App\Http\Controllers\Api\Commercial\CashRegisterController;
use App\Http\Controllers\Api\Commercial\CommissionController;
use App\Http\Controllers\Api\Commercial\CommissionRuleController;
use App\Http\Controllers\Api\Commercial\CommissionSettlementController;
use App\Http\Controllers\Api\Commercial\FinancialAccountController;
use App\Http\Controllers\Api\Commercial\PartnerPayoutController;
use App\Http\Controllers\Api\Commercial\PaymentMethodController;
use App\Http\Controllers\Api\Commercial\PriceListController;
use App\Http\Controllers\Api\Commercial\ProductController;
use App\Http\Controllers\Api\Commercial\ProductGroupController;
use App\Http\Controllers\Api\Commercial\QuoteController;
use App\Http\Controllers\Api\Commercial\SaleController;
use App\Http\Controllers\Api\Commercial\ServicePackageController;
use App\Http\Controllers\Api\Commercial\SoldPackageController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\Crm\ChurnReasonController;
use App\Http\Controllers\Api\Crm\ClientInsightsReportController;
use App\Http\Controllers\Api\Crm\ClientMessageController;
use App\Http\Controllers\Api\Crm\ClientOriginController;
use App\Http\Controllers\Api\Crm\ClientSearchController;
use App\Http\Controllers\Api\Crm\ClientSegmentController;
use App\Http\Controllers\Api\Crm\ClientTagController;
use App\Http\Controllers\Api\Crm\MessageAutomationController;
use App\Http\Controllers\Api\Crm\MessageCampaignController;
use App\Http\Controllers\Api\Crm\MessageTemplateController;
use App\Http\Controllers\Api\Crm\TagController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DepositSettingsController;
use App\Http\Controllers\Api\DocumentTemplateController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\ExamRequestController;
use App\Http\Controllers\Api\ExamTypeController;
use App\Http\Controllers\Api\FavoriteController;
use App\Http\Controllers\Api\GeneratedDocumentController;
use App\Http\Controllers\Api\HospitalizationCareLogController;
use App\Http\Controllers\Api\HospitalizationClinicalActController;
use App\Http\Controllers\Api\HospitalizationController;
use App\Http\Controllers\Api\HospitalizationMedicationScheduleController;
use App\Http\Controllers\Api\HospitalizationProgressNoteController;
use App\Http\Controllers\Api\ImmunizationAdherenceReportController;
use App\Http\Controllers\Api\ImmunizationProductController;
use App\Http\Controllers\Api\ImmunizationProtocolController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LgpdController;
use App\Http\Controllers\Api\LostPetController;
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
use App\Http\Controllers\Api\PetImmunizationDoseController;
use App\Http\Controllers\Api\PetImmunizationPlanController;
use App\Http\Controllers\Api\PetImmunizationScheduleController;
use App\Http\Controllers\Api\PetInvoicesController;
use App\Http\Controllers\Api\PetTimelineController;
use App\Http\Controllers\Api\PetVetAccessController;
use App\Http\Controllers\Api\PetWeightController;
use App\Http\Controllers\Api\PrescriptionAlertsController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\PrescriptionItemPromotionController;
use App\Http\Controllers\Api\PrescriptionReadController;
use App\Http\Controllers\Api\Professional\AvailabilityController as ProfessionalAvailabilityController;
use App\Http\Controllers\Api\Professional\BlockedTimeController as ProfessionalBlockedTimeController;
use App\Http\Controllers\Api\ProfessionalClientController;
use App\Http\Controllers\Api\ProfessionalDashboardController;
use App\Http\Controllers\Api\ProfessionalLegalProfileController;
use App\Http\Controllers\Api\Public\BookingController;
use App\Http\Controllers\Api\Public\DocumentVerificationController;
use App\Http\Controllers\Api\Public\MasterDataController;
use App\Http\Controllers\Api\Public\PetCardController as PublicPetCardController;
use App\Http\Controllers\Api\Public\ProfessionalController;
use App\Http\Controllers\Api\Public\QuoteDecisionController;
use App\Http\Controllers\Api\Public\ReverseGeocodeController;
use App\Http\Controllers\Api\Public\SearchController;
use App\Http\Controllers\Api\Public\TeamController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\Reports\OperationalPanelController;
use App\Http\Controllers\Api\Reports\OperationalPanelExportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\Stock\PurchaseController;
use App\Http\Controllers\Api\Stock\PurchaseOrderController;
use App\Http\Controllers\Api\Stock\SaleReturnController;
use App\Http\Controllers\Api\Stock\StockCountController;
use App\Http\Controllers\Api\Stock\StockExitReasonController;
use App\Http\Controllers\Api\Stock\StockMovementController;
use App\Http\Controllers\Api\Stock\StockReportController;
use App\Http\Controllers\Api\Stock\SupplierController;
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
    // Fase 2 do fluxo de agendamento — equipe do estabelecimento (clínica/petshop/etc.).
    // Segmento literal "team" DEPOIS de um parâmetro dinâmico não colide com o `show`
    // acima (métodos e URIs completos diferentes), mas fica perto dele de propósito.
    Route::get('/professionals/{id}/team', [TeamController::class, 'index']);
    Route::get('/pet-card/{publicId}', [PublicPetCardController::class, 'show']);
    Route::get('/breeds', [BreedController::class, 'index']);

    // Verificação pública de documento gerado (atestado/termo) — contrato
    // docs/gap-simplesvet/specs/15-modelos-documento-receituario-assinatura-spec.md,
    // regra de negócio 6: confirma autenticidade sem expor conteúdo clínico.
    Route::get('/documents/verify/{code}', DocumentVerificationController::class);

    // Master data endpoints
    Route::get('/pathologies', [MasterDataController::class, 'pathologies']);
    Route::get('/vaccine-catalog', [MasterDataController::class, 'vaccineCatalog']);
    Route::get('/food-brands', [MasterDataController::class, 'foodBrands']);
    Route::get('/specialties', [MasterDataController::class, 'specialties']);
    Route::get('/food-allergies', [MasterDataController::class, 'foodAllergies']);
    Route::get('/dietary-restrictions', [MasterDataController::class, 'dietaryRestrictions']);
});

// Link de aprovação de orçamento sem login — docs/gap-simplesvet/24-orcamentos.md. Rotas
// soltas, cada uma com o próprio throttle (middleware de grupo SOMA — ver o grupo da busca
// acima). A decisão é mais apertada que a leitura: é a ação que grava consentimento.
Route::get('/public/quotes/{token}', [QuoteDecisionController::class, 'show'])
    ->middleware('throttle:30,1');
Route::post('/public/quotes/{token}/decide', [QuoteDecisionController::class, 'decide'])
    ->middleware('throttle:10,1');

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
    // Fase 2, item 5 — segmento literal "availability-days" ANTES de qualquer rota com
    // parâmetro dinâmico que pudesse colidir (não há hoje, mas segue a convenção do projeto).
    Route::get('/booking/availability-days', [BookingController::class, 'availabilityDays']);
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

    // Item 22 — catálogo de permissões do usuário logado, para o app montar
    // menu/UI sem renderizar item que o backend recusaria.
    Route::get('/me/permissions', \App\Http\Controllers\Api\MePermissionsController::class);

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

    // Item 23 — cadastros configuráveis por dono (pelagens, profissões, origem do cliente,
    // motivo de perda, feriados, boxes de internação). Leitura livre para qualquer usuário
    // autenticado (selects do app inteiro dependem disso); mutação exige `catalog.manage`.
    Route::prefix('catalogs/{type}')->group(function (): void {
        Route::get('/', [\App\Http\Controllers\Api\CatalogController::class, 'index']);

        Route::middleware('permission:catalog.manage')->group(function (): void {
            Route::post('/', [\App\Http\Controllers\Api\CatalogController::class, 'store']);
            Route::put('/{id}', [\App\Http\Controllers\Api\CatalogController::class, 'update']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\CatalogController::class, 'destroy']);
        });
    });

    // Item 26 — importação de planilha de clientes (CSV). Escopo por dono via
    // `CommercialScopeResolver`, mesma régua de `catalogs/{type}`; toda rota exige
    // `data.import` (restrita a owner/vet volante, nunca staff/vet empregado — ver
    // `RolesAndPermissionsSeeder`). Literal `templates/{entity}` antes de `{id}` para não
    // ser capturado pelo wildcard numérico.
    Route::prefix('data-imports')->middleware('permission:data.import')->group(function (): void {
        Route::get('/templates/{entity}', [\App\Http\Controllers\Api\DataImportController::class, 'template'])
            ->where('entity', 'clients|pets|products|services|vaccinations');
        Route::get('/', [\App\Http\Controllers\Api\DataImportController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\DataImportController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\DataImportController::class, 'show'])->whereNumber('id');
        Route::put('/{id}/mapping', [\App\Http\Controllers\Api\DataImportController::class, 'updateMapping'])->whereNumber('id');
        Route::post('/{id}/validate', [\App\Http\Controllers\Api\DataImportController::class, 'validateRows'])->whereNumber('id');
        Route::post('/{id}/execute', [\App\Http\Controllers\Api\DataImportController::class, 'execute'])->whereNumber('id');
        Route::post('/{id}/rollback', [\App\Http\Controllers\Api\DataImportController::class, 'rollback'])->whereNumber('id');
        Route::get('/{id}/errors/export', [\App\Http\Controllers\Api\DataImportController::class, 'exportErrors'])->whereNumber('id');
    });

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

    // Item 22 — feed de auditoria genérico por registro, ponto de extensão para os demais
    // recursos (ver `ActivityLogController::RESOURCE_MAP`). Hoje só `pets` está ligado.
    Route::get('/activity-log/{resource}/{id}', [\App\Http\Controllers\Api\ActivityLogController::class, 'index']);

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

    // Plano de imunização do pet (protocolo vacinal/vermifugação) — contrato
    // docs/gap-simplesvet/specs/13-protocolos-vacinais-spec.md. Leitura compartilhada
    // tutor + vet autorizado (carteirinha virtual); iniciar plano/aplicar/pular dose exige
    // ser veterinário (checado no controller).
    Route::get('/pets/{pet}/immunization-schedule', PetImmunizationScheduleController::class);
    Route::get('/pets/{pet}/immunization-plans', [PetImmunizationPlanController::class, 'index']);
    Route::post('/pets/{pet}/immunization-plans', [PetImmunizationPlanController::class, 'store']);
    Route::get('/pets/{pet}/immunization-plans/{plan}', [PetImmunizationPlanController::class, 'show']);
    Route::post(
        '/pets/{pet}/immunization-plans/{plan}/doses/{dose}/apply',
        [PetImmunizationDoseController::class, 'apply']
    );
    Route::post(
        '/pets/{pet}/immunization-plans/{plan}/doses/{dose}/skip',
        [PetImmunizationDoseController::class, 'skip']
    );

    // Orçamentos — docs/gap-simplesvet/24-orcamentos.md. `pets/{pet}/quotes` tem dupla
    // audiência (tutor dono × escopo comercial do profissional), mesmo espírito de
    // `pets/{pet}/invoices`. `me/quotes` é o tutor decidindo pelo app.
    Route::get('/pets/{pet}/quotes', \App\Http\Controllers\Api\PetQuotesController::class);
    Route::get('/me/quotes', [\App\Http\Controllers\Api\TutorQuoteController::class, 'index']);
    Route::get('/me/quotes/{id}', [\App\Http\Controllers\Api\TutorQuoteController::class, 'show']);
    Route::post('/me/quotes/{id}/approve', [\App\Http\Controllers\Api\TutorQuoteController::class, 'approve']);
    Route::post('/me/quotes/{id}/reject', [\App\Http\Controllers\Api\TutorQuoteController::class, 'reject']);

    // `me/pending-links` — contrato docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md
    // item 3: mesmo endpoint de `pet-vet-access/pending`, pré-filtrado para o caso "vet me
    // cadastrou como paciente novo". Aceitar/recusar continua em `pet-vet-access/{id}/accept|reject`
    // — não há ação nova, só uma vitrine dedicada para o app do tutor.
    Route::get('/me/pending-links', [PetVetAccessController::class, 'pendingLinksForTutor']);

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

        // Tutor → responde solicitações pendentes. `?origin=` filtra (ex.:
        // `new_patient_pending_request` para o caso "vet me cadastrou como paciente novo" —
        // é o `me/pending-links` de docs/gap-simplesvet/specs/19-portal-do-cliente-spec.md,
        // sem duplicar rota/controller para o mesmo dado.
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
        // Override pontual de permissão por membro — item 22 do backlog gap-simplesvet. Só
        // dono (`OrganizationPolicy::manageMembers`, checado em
        // `UpdateOrganizationMemberPermissionsRequest::authorize()`); nunca ato clínico.
        Route::put('/members/{member}/permissions', [\App\Http\Controllers\Api\OrganizationMemberController::class, 'updatePermissions']);
        Route::delete('/members/{member}', [\App\Http\Controllers\Api\OrganizationMemberController::class, 'destroy']);

        Route::post('/invitations', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'store']);
        Route::get('/invitations', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'index']);
        Route::delete('/invitations/{invitation}', [\App\Http\Controllers\Api\OrganizationInvitationController::class, 'destroy']);

        // Áreas de atendimento — item 21 do backlog gap-simplesvet. Leitura livre para
        // qualquer membro autenticado; mutação exige `service-areas.manage`.
        Route::get('/service-areas', [\App\Http\Controllers\Api\ServiceAreaController::class, 'index']);
        Route::middleware('permission:service-areas.manage')->group(function (): void {
            Route::post('/service-areas', [\App\Http\Controllers\Api\ServiceAreaController::class, 'store']);
            Route::put('/service-areas/{id}', [\App\Http\Controllers\Api\ServiceAreaController::class, 'update']);
            Route::delete('/service-areas/{id}', [\App\Http\Controllers\Api\ServiceAreaController::class, 'destroy']);
            Route::put('/members/{member}/service-areas', [\App\Http\Controllers\Api\ServiceAreaController::class, 'syncMemberAreas']);
        });
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

    // Extrato do próprio tutor numa clínica — contrato docs/gap-simplesvet/specs/
    // 11-conta-corrente-do-cliente-spec.md ("vantagem sobre o SimplesVet"). Sempre o saldo de
    // QUEM ESTÁ LOGADO, nunca um client_id vindo do app.
    Route::get('me/clinics/{organizationId}/account-statement', [\App\Http\Controllers\Api\ClientAccountStatementController::class, 'show'])
        ->whereNumber('organizationId')
        ->middleware('permission:client-account.view.own|client-account.view.any');

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

        // Confirmar/recusar — Fase 4 do fluxo de agendamento: endpoints EXPLÍCITOS (o app
        // mostra dois botões numa notificação push, não um formulário de edição). Segmentos
        // literais ANTES do apiResource, mesma armadilha já documentada acima.
        Route::post('appointments/{id}/confirm', [AppointmentConfirmationController::class, 'confirm']);
        Route::post('appointments/{id}/reject', [AppointmentConfirmationController::class, 'reject']);

        // Check-in de recepção — item 21 do backlog gap-simplesvet ("fila do dia").
        Route::post('appointments/{id}/check-in', \App\Http\Controllers\Api\AppointmentCheckInController::class);

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

        // Protocolos vacinais/de vermifugação — contrato docs/gap-simplesvet/specs/
        // 13-protocolos-vacinais-spec.md. Catálogo unificado (vacina/vermífugo/
        // antiparasitário) + grafo de doses + plano do pet. Permissões do catálogo
        // (docs/gap-simplesvet/specs/permissoes-catalogo.md): leitura = `.view`, mutação =
        // `.manage` — não substitui `OrganizationCatalogGate` (escopo por organização),
        // só decide se o papel pode tocar a feature.
        Route::get('immunization-products', [ImmunizationProductController::class, 'index'])
            ->middleware('permission:vaccine-protocols.view');
        Route::post('immunization-products', [ImmunizationProductController::class, 'store'])
            ->middleware('permission:vaccine-protocols.manage');
        Route::get('immunization-products/{immunizationProduct}', [ImmunizationProductController::class, 'show'])
            ->middleware('permission:vaccine-protocols.view');
        Route::put('immunization-products/{immunizationProduct}', [ImmunizationProductController::class, 'update'])
            ->middleware('permission:vaccine-protocols.manage');
        Route::delete('immunization-products/{immunizationProduct}', [ImmunizationProductController::class, 'destroy'])
            ->middleware('permission:vaccine-protocols.manage');

        Route::get('immunization-products/{immunizationProduct}/protocols', [ImmunizationProtocolController::class, 'index'])
            ->middleware('permission:vaccine-protocols.view');
        Route::post('immunization-products/{immunizationProduct}/protocols', [ImmunizationProtocolController::class, 'store'])
            ->middleware('permission:vaccine-protocols.manage');
        Route::get('immunization-products/{immunizationProduct}/protocols/{protocol}', [ImmunizationProtocolController::class, 'show'])
            ->middleware('permission:vaccine-protocols.view');
        Route::delete('immunization-products/{immunizationProduct}/protocols/{protocol}', [ImmunizationProtocolController::class, 'destroy'])
            ->middleware('permission:vaccine-protocols.manage');

        Route::get('reports/immunization-adherence', ImmunizationAdherenceReportController::class);

        // Prescriptions — segmentos literais ANTES do apiResource (mesma armadilha já
        // documentada para "pending"/"walk-in" acima).
        Route::get('prescriptions/valid', [PrescriptionController::class, 'valid']);
        Route::post('prescriptions/{id}/issue', [PrescriptionController::class, 'issue']);
        Route::post('prescriptions/{id}/cancel', [PrescriptionController::class, 'cancel']);
        // Assinatura eletrônica simples — contrato docs/gap-simplesvet/specs/
        // 15-modelos-documento-receituario-assinatura-spec.md.
        Route::post('prescriptions/{id}/sign', [PrescriptionController::class, 'sign']);
        // "Duplicar prescrição" — contrato docs/gap-simplesvet/specs/
        // 12-internacao-mapa-execucao-spec.md (alternativa a modelo de prescrição nomeado).
        Route::post('prescriptions/{id}/duplicate', [PrescriptionController::class, 'duplicate']);
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

        // "Próxima dose esperada" derivada em leitura — contrato docs/gap-simplesvet/specs/
        // 12-internacao-mapa-execucao-spec.md §3. Uma única query agregada, sem grade 24×N.
        Route::get('hospitalizations/{id}/medication-schedule', HospitalizationMedicationScheduleController::class);

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

        // Sinal (pagamento parcial antecipado) do estabelecimento — Fase 6 do fluxo de
        // agendamento. Override por serviço vem junto do próprio `services` acima
        // (`deposit_enabled`/`deposit_percentage` no payload de store/update).
        Route::get('deposit-settings', [DepositSettingsController::class, 'show']);
        Route::put('deposit-settings', [DepositSettingsController::class, 'update']);

        // ---------------------------------------------------------------
        // Catálogo comercial — contrato docs/gap-simplesvet/08-produtos-
        // precificacao-lista-precos.md, consolidado com o antigo estoque clínico em
        // docs/gap-simplesvet/specs/produtos-estoque-consolidado-spec.md
        // (`InventoryController`/`inventory` removidos — `products` é a única fonte de
        // verdade de produto/estoque). Escopo por organização em `CommercialScopeResolver`,
        // nunca por `professional_id` solto.
        //
        // Permissão por verbo (item 4 da spec de consolidação: "recepção lê price-list, não
        // products"): `products.view` para leitura, `products.create/update/delete` para
        // escrita — só quem já mexia em `inventory`/catálogo comercial antes tem essas
        // permissões (ver `RolesAndPermissionsSeeder`).
        // ---------------------------------------------------------------

        // Segmento literal ANTES do apiResource: sem isto `{product}` capturaria
        // "lookup" como id — mesma armadilha já documentada em "pending"/"walk-in".
        Route::get('products/lookup', [ProductController::class, 'lookup'])->middleware('permission:products.view');
        Route::apiResource('products', ProductController::class)
            ->middlewareFor(['index', 'show'], 'permission:products.view')
            ->middlewareFor('store', 'permission:products.create')
            ->middlewareFor('update', 'permission:products.update')
            ->middlewareFor('destroy', 'permission:products.delete');

        Route::apiResource('product-groups', ProductGroupController::class)->except(['show']);
        Route::apiResource('brands', BrandController::class)->except(['show']);

        // Lista de preços do balcão — `export` antes de qualquer rota com parâmetro.
        Route::middleware('permission:price-lists.view')->group(function (): void {
            Route::get('price-list/export', [PriceListController::class, 'export']);
            Route::get('price-list', [PriceListController::class, 'index']);
        });

        // Pacotes de serviços vendidos — contrato docs/gap-simplesvet/specs/
        // 10-pacotes-de-servicos-vendidos-spec.md. Terceiro `Sellable` (produto/serviço já
        // existiam); `sold-packages` reaproveita `sale_items` polimórfico, sem venda paralela.
        //
        // Item 22, passada final: `service-packages.view`/`.manage` já existiam no catálogo e
        // já eram atribuídas por papel no seeder — só faltava o `->middleware(...)` aqui. A
        // nota antiga sobre `BuildsCounterFixtures` quebrar por falta de seed não se aplica
        // mais: a fixture já roda `RolesAndPermissionsSeeder` e o
        // `OrganizationMemberRoleReconciliationObserver` já reconcilia o papel Spatie de cada
        // membro criado por factory (ver `app/Observers/Organization/
        // OrganizationMemberRoleReconciliationObserver.php`).
        Route::middleware('permission:service-packages.view')->group(function (): void {
            Route::apiResource('service-packages', ServicePackageController::class)->only(['index', 'show']);
        });
        Route::middleware('permission:service-packages.manage')->group(function (): void {
            Route::apiResource('service-packages', ServicePackageController::class)->only(['store', 'update', 'destroy']);
        });

        // `sold-packages` é consumo de balcão (qualquer membro ativo), não gestão de catálogo
        // — mesma permissão de LEITURA do catálogo (`service-packages.view`) cobre também
        // `consume`: resgatar um pacote já vendido não é "gerenciar o catálogo de pacotes".
        Route::middleware('permission:service-packages.view')->group(function (): void {
            Route::post('sold-packages/{id}/consume', [SoldPackageController::class, 'consume']);
            Route::get('sold-packages/{id}', [SoldPackageController::class, 'show']);
            Route::get('sold-packages', [SoldPackageController::class, 'index']);
            Route::get('pets/{id}/available-packages', [SoldPackageController::class, 'availableForPet']);
            Route::get('clients/{id}/sold-packages', [SoldPackageController::class, 'forClient']);
        });

        // Comissionamento interno e repasses — contrato docs/gap-simplesvet/specs/
        // 09-comissionamento-interno-repasses-spec.md. NÃO é o `commissions`/`payouts` do
        // take rate da plataforma (fluxo antigo, intocado) — é a clínica comissionando o
        // próprio staff. `open`/`me` são segmentos literais antes do apiResource-like abaixo.
        //
        // Item 22, passada final: mesma nota da seção "Pacotes de serviços vendidos" acima —
        // a fixture compartilhada já foi corrigida, `permission:` aplicado de verdade. A
        // autorização por registro continua 100% via Policy (`CommissionRulePolicy`/
        // `CommissionSettlementPolicy`/`ownsOrganization()`), que resolve "próprio × qualquer"
        // — o middleware só decide quem pode tocar a feature (regra de negócio 5 continua
        // garantida pela Policy, não pelo middleware).
        Route::get('me/commissions', [CommissionController::class, 'myCommissions'])
            ->middleware('permission:commissions.view.own|commissions.view.any');
        // `open` exige a MESMA ability (`create` em `CommissionSettlement`) de `store`/`index`
        // abaixo — owner (ou vet volante solo) apenas. `commissions.rule.manage`, não
        // `.view.any`: `vet_freelancer` nunca tem `.view.any` (não tem equipe), só `.rule.manage`.
        Route::get('commissions/open', [CommissionSettlementController::class, 'open'])
            ->middleware('permission:commissions.rule.manage');

        Route::middleware('permission:commissions.rule.manage')->group(function (): void {
            Route::apiResource('commission-rules', CommissionRuleController::class)->except(['show']);
            Route::post('commission-settlements/{id}/pay', [CommissionSettlementController::class, 'pay']);
            Route::get('commission-settlements', [CommissionSettlementController::class, 'index']);
            Route::post('commission-settlements', [CommissionSettlementController::class, 'store']);
        });
        // `show` é dupla audiência (dono OU o próprio funcionário do fechamento,
        // `CommissionSettlementPolicy::view()`) — nunca `.rule.manage` sozinho, ou o
        // funcionário perderia acesso ao próprio fechamento.
        Route::get('commission-settlements/{id}', [CommissionSettlementController::class, 'show'])
            ->middleware('permission:commissions.view.own|commissions.view.any');

        // Repasse a parceiro terceiro — sem permissão própria no catálogo até esta rodada
        // (achado registrado no contrato 09: "crie a permissão se faltar"). População idêntica
        // a `financial-accounts.manage`/`fiscal-documents.issue` (owner, ou o próprio
        // profissional sem organização) — `partner-payouts.manage` nova no seeder.
        Route::middleware('permission:partner-payouts.manage')->group(function (): void {
            // "candidates" é segmento literal ANTES de `partner-payouts/{id}` — mesma armadilha
            // de "lookup"/"pending" já documentada em outras rotas deste arquivo.
            Route::get('partner-candidates', [PartnerPayoutController::class, 'candidates']);
            Route::post('partner-payouts/{id}/reconcile', [PartnerPayoutController::class, 'reconcile']);
            Route::apiResource('partner-payouts', PartnerPayoutController::class)->only(['index', 'store']);
        });

        // ---------------------------------------------------------------
        // Compras e estoque — contratos docs/gap-simplesvet/06 (compras,
        // fornecedores, XML de NF-e) e 07 (livro de movimentos, inventário,
        // devolução, análise de giro). Payloads em
        // docs/gap-simplesvet/06-07-contrato-api.md. Segmentos literais
        // ANTES de cada apiResource (mesma armadilha de "lookup" acima).
        // ---------------------------------------------------------------
        Route::apiResource('suppliers', SupplierController::class);

        Route::post('purchases/xml-preview', [PurchaseController::class, 'xmlPreview']);
        Route::post('purchases/{id}/receive', [PurchaseController::class, 'receive']);
        Route::post('purchases/{id}/cancel', [PurchaseController::class, 'cancel']);
        Route::apiResource('purchases', PurchaseController::class);

        Route::post('purchase-orders/{id}/send', [PurchaseOrderController::class, 'send']);
        Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel']);
        Route::post('purchase-orders/{id}/receive', [PurchaseOrderController::class, 'receive']);
        Route::apiResource('purchase-orders', PurchaseOrderController::class);

        Route::get('products/{id}/stock-movements', [StockMovementController::class, 'product']);
        Route::get('products/{id}/batches', [StockMovementController::class, 'batches']);
        Route::get('stock-movements', [StockMovementController::class, 'index']);
        Route::post('stock-movements', [StockMovementController::class, 'store']);

        Route::apiResource('stock-exit-reasons', StockExitReasonController::class)->except(['show']);

        Route::put('stock-counts/{id}/items', [StockCountController::class, 'updateItems']);
        Route::post('stock-counts/{id}/close', [StockCountController::class, 'close']);
        Route::apiResource('stock-counts', StockCountController::class)->except(['update']);

        Route::get('sale-returns/sale-lookup', [SaleReturnController::class, 'saleLookup']);
        Route::apiResource('sale-returns', SaleReturnController::class)->only(['index', 'show', 'store']);

        Route::get('reports/stock-analysis', [StockReportController::class, 'analysis']);
        Route::get('reports/stock-expiring', [StockReportController::class, 'expiring']);

        // Orçamentos — docs/gap-simplesvet/24-orcamentos.md. `sales.kind = quote`: itens e
        // desconto pelo mesmo `SaleService` do PDV; ciclo de envio/decisão/revisão no
        // `QuoteService`.
        Route::get('quotes', [QuoteController::class, 'index']);
        Route::post('quotes', [QuoteController::class, 'store']);
        Route::get('quotes/{id}', [QuoteController::class, 'show']);
        Route::put('quotes/{id}', [QuoteController::class, 'update']);
        Route::post('quotes/{id}/items', [QuoteController::class, 'storeItem']);
        Route::delete('quotes/{id}/items/{itemId}', [QuoteController::class, 'destroyItem']);
        Route::post('quotes/{id}/discount', [QuoteController::class, 'applyDiscount']);
        Route::post('quotes/{id}/send', [QuoteController::class, 'send']);
        Route::post('quotes/{id}/revise', [QuoteController::class, 'revise']);
        Route::post('quotes/{id}/convert', [QuoteController::class, 'convert']);
        Route::get('quotes/{id}/pdf', [QuoteController::class, 'pdf']);

        // ---------------------------------------------------------------
        // Caixa e Ponto de Venda — contrato docs/gap-simplesvet/01-caixa-pdv.md.
        // Regras em `CashRegisterService`/`SaleService`, autorização em
        // `CashRegisterPolicy`/`SalePolicy`, escopo em `CommercialScopeResolver`.
        // ---------------------------------------------------------------

        // Item 22, passada final: `payment-methods` (doc 04) não tinha NENHUMA autorização —
        // `StorePaymentMethodRequest::authorize()` só checava `user() !== null`. A spec diz
        // "ver é qualquer membro ativo, cadastrar/editar é só OWNER" — sem permissão própria no
        // catálogo, reaproveita `financial-accounts.*` (mesma fronteira "dinheiro real da
        // empresa" do doc 04).
        Route::get('payment-methods', [PaymentMethodController::class, 'index'])
            ->middleware('permission:financial-accounts.view');
        Route::middleware('permission:financial-accounts.manage')->group(function (): void {
            Route::post('payment-methods', [PaymentMethodController::class, 'store']);
            Route::match(['put', 'patch'], 'payment-methods/{id}', [PaymentMethodController::class, 'update']);
        });

        // Contas bancárias/caixa/operadora e conciliação de cartões — doc 04.
        Route::middleware('permission:financial-accounts.view')->group(function (): void {
            Route::get('financial-accounts/{id}/balance', [FinancialAccountController::class, 'balance'])->whereNumber('id');
            Route::get('financial-accounts/{id}/statement', [FinancialAccountController::class, 'statement'])->whereNumber('id');
            Route::get('financial-accounts', [FinancialAccountController::class, 'index']);
            Route::get('financial-accounts/{id}', [FinancialAccountController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('permission:financial-accounts.manage')->group(function (): void {
            Route::post('financial-accounts', [FinancialAccountController::class, 'store']);
            Route::match(['put', 'patch'], 'financial-accounts/{id}', [FinancialAccountController::class, 'update'])->whereNumber('id');
        });

        Route::middleware('permission:acquirer-settlements.view')->group(function (): void {
            Route::get('acquirer-settlements/expected', [AcquirerSettlementController::class, 'expected']);
            Route::get('acquirer-settlements', [AcquirerSettlementController::class, 'index']);
            Route::get('acquirer-settlements/{id}', [AcquirerSettlementController::class, 'show'])->whereNumber('id');
        });
        Route::middleware('permission:acquirer-settlements.manage')->group(function (): void {
            Route::post('acquirer-settlements/{id}/reconcile', [AcquirerSettlementController::class, 'reconcile'])->whereNumber('id');
            Route::post('acquirer-settlements/{id}/mark-divergent', [AcquirerSettlementController::class, 'markDivergent'])->whereNumber('id');
            Route::post('acquirer-settlements', [AcquirerSettlementController::class, 'store']);
        });

        // "current" é segmento literal ANTES de `{id}` — mesma armadilha de "lookup" acima.
        Route::get('cash-registers/current', [CashRegisterController::class, 'current']);
        Route::get('cash-registers', [CashRegisterController::class, 'index']);
        Route::post('cash-registers', [CashRegisterController::class, 'store']);
        Route::get('cash-registers/{id}', [CashRegisterController::class, 'show'])->whereNumber('id');
        Route::get('cash-registers/{id}/movements', [CashRegisterController::class, 'movements'])->whereNumber('id');
        Route::post('cash-registers/{id}/movements', [CashRegisterController::class, 'storeMovement'])->whereNumber('id');
        Route::get('cash-registers/{id}/preview', [CashRegisterController::class, 'preview'])->whereNumber('id');
        Route::post('cash-registers/{id}/close', [CashRegisterController::class, 'close'])->whereNumber('id');
        Route::post('cash-registers/{id}/settle', [CashRegisterController::class, 'settle'])->whereNumber('id');
        Route::post('cash-registers/{id}/review', [CashRegisterController::class, 'review'])->whereNumber('id');

        // Financeiro unificado: faturas de atendimento + vendas do PDV (doc 01 — "o PDV
        // reflete no financeiro"). Regras em `FinancialOverviewService`. SEM `permission:`
        // (achado desta passada final, registrado — não fechado): mistura fatura (aberta a
        // mais papéis) e venda de balcão, nenhum item do catálogo (item 22) cobre exatamente
        // essa mistura; mantido na allowlist até o produto decidir a régua.
        Route::get('financial/summary', [\App\Http\Controllers\Api\Finance\FinancialOverviewController::class, 'summary']);
        Route::get('financial/entries', [\App\Http\Controllers\Api\Finance\FinancialOverviewController::class, 'entries']);

        // Plano de contas, lançamentos, DRE e fluxo de caixa — doc 02. Só OWNER (ver spec
        // "Permissões por papel"): visão financeira consolidada, não operação de balcão.
        Route::middleware('permission:chart-of-accounts.view')->group(function (): void {
            Route::get('financial-categories/tree', [\App\Http\Controllers\Api\Finance\FinancialCategoryController::class, 'tree']);
            Route::get('financial-categories', [\App\Http\Controllers\Api\Finance\FinancialCategoryController::class, 'index']);
        });
        Route::middleware('permission:chart-of-accounts.manage')->group(function (): void {
            Route::apiResource('financial-categories', \App\Http\Controllers\Api\Finance\FinancialCategoryController::class)
                ->only(['store', 'update', 'destroy'])
                ->parameters(['financial-categories' => 'id']);
        });

        Route::middleware('permission:financial-entries.view.own|financial-entries.view.any')->group(function (): void {
            Route::apiResource('financial-entries', \App\Http\Controllers\Api\Finance\FinancialEntryController::class)
                ->only(['index', 'show'])
                ->parameters(['financial-entries' => 'id']);
            Route::apiResource('financial-transfers', \App\Http\Controllers\Api\Finance\FinancialTransferController::class)
                ->only(['index']);
            // Contas a pagar/a receber — doc 03. Visão sobre `financial_entries` (doc 02), sem
            // tabela própria de dívida (reaproveita a mesma permissão — herda a Policy).
            // Calendário de feriados NÃO tem rota própria aqui: consolidado com o catálogo do
            // item 23 — ver `GET/POST/PUT/DELETE catalogs/holidays` (`CatalogController`) e
            // `App\Models\Holiday`.
            Route::get('reports/accounts-payable', [\App\Http\Controllers\Api\Finance\AccountsLedgerController::class, 'payable']);
            Route::get('reports/accounts-receivable', [\App\Http\Controllers\Api\Finance\AccountsLedgerController::class, 'receivable']);
        });
        Route::middleware('permission:financial-entries.create')->group(function (): void {
            Route::apiResource('financial-entries', \App\Http\Controllers\Api\Finance\FinancialEntryController::class)
                ->only(['store'])
                ->parameters(['financial-entries' => 'id']);
            Route::apiResource('financial-transfers', \App\Http\Controllers\Api\Finance\FinancialTransferController::class)
                ->only(['store']);
        });
        Route::middleware('permission:financial-entries.update')->group(function (): void {
            Route::patch('financial-entries/series/{seriesId}', [\App\Http\Controllers\Api\Finance\FinancialEntryController::class, 'updateSeries']);
            Route::post('financial-entries/{id}/settle', [\App\Http\Controllers\Api\Finance\FinancialEntryController::class, 'settle'])->whereNumber('id');
            Route::post('financial-entries/{id}/unsettle', [\App\Http\Controllers\Api\Finance\FinancialEntryController::class, 'unsettle'])->whereNumber('id');
            Route::match(['put', 'patch'], 'financial-entries/{id}', [\App\Http\Controllers\Api\Finance\FinancialEntryController::class, 'update'])->whereNumber('id');
        });

        Route::get('reports/income-statement', [\App\Http\Controllers\Api\Finance\FinancialReportController::class, 'incomeStatement'])
            ->middleware('permission:reports.dre.view');
        Route::get('reports/cash-flow', [\App\Http\Controllers\Api\Finance\FinancialReportController::class, 'cashFlow'])
            ->middleware('permission:reports.cash-flow.view');

        // Segmentos literais do balcão ANTES do `{id}` das vendas.
        Route::get('sales/form-options', [SaleController::class, 'formOptions']);
        Route::get('sales/catalog', [SaleController::class, 'catalog']);
        Route::get('sales/clients', [SaleController::class, 'clients']);
        Route::get('sales', [SaleController::class, 'index']);
        Route::post('sales', [SaleController::class, 'store']);
        Route::get('sales/{id}', [SaleController::class, 'show'])->whereNumber('id');
        Route::patch('sales/{id}', [SaleController::class, 'update'])->whereNumber('id');
        Route::post('sales/{id}/items', [SaleController::class, 'storeItem'])->whereNumber('id');
        Route::patch('sales/{id}/items/{itemId}', [SaleController::class, 'updateItem'])->whereNumber(['id', 'itemId']);
        Route::delete('sales/{id}/items/{itemId}', [SaleController::class, 'destroyItem'])->whereNumber(['id', 'itemId']);
        Route::post('sales/{id}/discount', [SaleController::class, 'applyDiscount'])->whereNumber('id');
        Route::get('sales/{id}/receipts', [SaleController::class, 'receipts'])->whereNumber('id');
        Route::post('sales/{id}/receipts', [SaleController::class, 'storeReceipt'])->whereNumber('id');
        Route::post('sales/{id}/convert', [SaleController::class, 'convert'])->whereNumber('id');
        Route::post('sales/{id}/cancel', [SaleController::class, 'cancel'])->whereNumber('id');

        // Emissão fiscal — contrato docs/gap-simplesvet/specs/
        // 05-emissao-fiscal-nfe-nfce-nfse-spec.md. Só OWNER (`FiscalDocumentPolicy`).
        Route::middleware('permission:fiscal-documents.issue')->group(function (): void {
            Route::post('sales/{id}/fiscal-documents', [\App\Http\Controllers\Api\Fiscal\FiscalDocumentController::class, 'store'])->whereNumber('id');
        });
        Route::middleware('permission:fiscal-documents.view')->group(function (): void {
            Route::get('fiscal-documents', [\App\Http\Controllers\Api\Fiscal\FiscalDocumentController::class, 'index']);
            Route::get('fiscal-documents/{id}', [\App\Http\Controllers\Api\Fiscal\FiscalDocumentController::class, 'show'])->whereNumber('id');
            Route::get('reports/fiscal-pending', [\App\Http\Controllers\Api\Fiscal\FiscalDocumentController::class, 'pending']);
        });
        Route::post('fiscal-documents/{id}/cancel', [\App\Http\Controllers\Api\Fiscal\FiscalDocumentController::class, 'cancel'])
            ->whereNumber('id')
            ->middleware('permission:fiscal-documents.cancel');
        // Config fiscal (regime, inscrições) — leitura e escrita são o mesmo `fiscal-settings.manage`:
        // não há audiência de "só ver a configuração" no catálogo, e a spec 05 já restringe as
        // duas a OWNER.
        Route::middleware('permission:fiscal-settings.manage')->group(function (): void {
            Route::get('fiscal-settings', [\App\Http\Controllers\Api\Fiscal\FiscalSettingsController::class, 'show']);
            Route::put('fiscal-settings', [\App\Http\Controllers\Api\Fiscal\FiscalSettingsController::class, 'update']);
        });

        // My patients — pets this vet has active PetVetAccess grants for (enriched list).
        Route::get('my-patients', [PetVetAccessController::class, 'myPatients']);

        // Clients
        Route::apiResource('clients', ProfessionalClientController::class);
        Route::get('clients/{id}/pets', [ProfessionalClientController::class, 'pets']);

        // Portal do cliente — convite fora do fluxo de agendamento (docs/gap-simplesvet/
        // specs/19-portal-do-cliente-spec.md, Achado 1).
        Route::post('clients/{id}/invite', [ProfessionalClientController::class, 'invite']);
        Route::get('clients/{id}/portal-status', [ProfessionalClientController::class, 'portalStatus']);

        // Conta corrente do cliente — contrato docs/gap-simplesvet/specs/
        // 11-conta-corrente-do-cliente-spec.md.
        Route::get('clients/{id}/account-statement', [\App\Http\Controllers\Api\Commercial\ClientAccountController::class, 'statement'])
            ->whereNumber('id')
            ->middleware('permission:client-account.view.own|client-account.view.any');
        Route::middleware('permission:client-account.manage')->group(function (): void {
            Route::post('clients/{id}/account-entries', [\App\Http\Controllers\Api\Commercial\ClientAccountController::class, 'storeEntry'])->whereNumber('id');
            Route::put('clients/{id}/account-settings', [\App\Http\Controllers\Api\Commercial\ClientAccountController::class, 'updateSettings'])->whereNumber('id');
        });

        // Segmentação de clientes — docs/gap-simplesvet/specs/18-segmentacao-clientes-spec.md.
        // `POST clients/search` (não GET): definição de segmento não cabe em query string com
        // segurança/tamanho, mesma regra de docs/gap-simplesvet/06-07-contrato-api.md.
        //
        // `clients.contact.view-bulk` (item 22, passada final): `search()`/`abcRanking()` são
        // as duas telas desta fatia que devolvem telefone/e-mail de MÚLTIPLOS tutores na
        // mesma resposta (`ClientRelationshipProfileResource`) — exposição de contato em
        // massa, não um único registro (mesma régua do painel operacional abaixo).
        // `relationship-profile` (um cliente só) e `lifecycle-distribution` (agregado sem PII)
        // ficam de fora de propósito.
        Route::post('clients/search', [ClientSearchController::class, 'search'])
            ->middleware('permission:clients.contact.view-bulk');
        Route::get('clients/{id}/relationship-profile', [ClientSearchController::class, 'show'])->whereNumber('id');
        Route::get('clients/{id}/tags', [ClientTagController::class, 'index']);
        Route::post('clients/{id}/tags', [ClientTagController::class, 'attach']);
        Route::delete('clients/{id}/tags/{tag}', [ClientTagController::class, 'detach']);
        // `client-origins`/`churn-reasons` ficam SEM `permission:` de propósito (achado desta
        // passada final, registrado — não fechado): `ClientOriginPolicy`/`ChurnReasonPolicy`
        // usam `OrganizationCatalogGate::canManage()`, que também deixa o veterinário
        // empregado (`hasClinicalAccessToOrganization`) gerenciar — nenhuma permissão do
        // catálogo hoje cobre "owner OU vet empregado" para catálogo administrativo (as
        // outras usam só `.manage` owner-only); criar uma permissão nova para só estes dois
        // recursos é decisão de produto, não feita aqui.
        Route::apiResource('client-origins', ClientOriginController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('churn-reasons', ChurnReasonController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::middleware('permission:crm.segment.manage')->group(function (): void {
            Route::apiResource('client-segments', ClientSegmentController::class)->only(['store', 'update', 'destroy']);
        });
        Route::apiResource('client-segments', ClientSegmentController::class)->only(['index', 'show']);
        Route::get('tags', [TagController::class, 'index']);
        Route::post('tags', [TagController::class, 'store']);
        Route::delete('tags/{tag}', [TagController::class, 'destroy']);
        Route::get('reports/client-ranking-abc', [ClientInsightsReportController::class, 'abcRanking'])
            ->middleware('permission:clients.contact.view-bulk');
        Route::get('reports/client-lifecycle-distribution', [ClientInsightsReportController::class, 'lifecycleDistribution']);

        // Painéis operacionais — docs/gap-simplesvet/specs/25-paineis-operacionais-spec.md.
        // Segmentos literais ANTES de qualquer rota com `{id}` deste grupo, mesma armadilha
        // já documentada em outros pontos deste arquivo. `clients.contact.view-bulk`: as 3
        // telas mostram telefone/e-mail de múltiplos tutores na mesma página (regra de
        // negócio 4 da spec) — mesma permissão do bloco de segmentação acima.
        Route::middleware('permission:clients.contact.view-bulk')->group(function (): void {
            Route::get('reports/immunization', [OperationalPanelController::class, 'immunization']);
            Route::get('reports/birthdays', [OperationalPanelController::class, 'birthdays']);
            Route::get('reports/clinic-events', [OperationalPanelController::class, 'clinicEvents']);
            Route::get('reports/immunization/export', [OperationalPanelExportController::class, 'immunization']);
            Route::get('reports/birthdays/export', [OperationalPanelExportController::class, 'birthdays']);
            Route::get('reports/clinic-events/export', [OperationalPanelExportController::class, 'clinicEvents']);
        });

        // CRM — mensageria automática e campanhas — docs/gap-simplesvet/specs/
        // 17-crm-mensageria-spec.md. `preview`/`{id}/send`/`{id}/cancel` literais ANTES do
        // `apiResource`, mesma armadilha de sempre.
        Route::post('message-campaigns/{messageCampaign}/send', [MessageCampaignController::class, 'send'])
            ->middleware('permission:crm.campaign.send');
        Route::middleware('permission:crm.campaign.manage')->group(function (): void {
            Route::post('message-campaigns/preview', [MessageCampaignController::class, 'preview']);
            Route::post('message-campaigns/{messageCampaign}/cancel', [MessageCampaignController::class, 'cancel']);
            Route::apiResource('message-campaigns', MessageCampaignController::class)->except(['update']);
        });
        Route::middleware('permission:crm.templates.manage')->group(function (): void {
            Route::apiResource('message-templates', MessageTemplateController::class);
        });
        Route::middleware('permission:crm.automations.manage')->group(function (): void {
            Route::post('message-automations/{messageAutomation}/test', [MessageAutomationController::class, 'test']);
            Route::apiResource('message-automations', MessageAutomationController::class)->except(['show']);
        });
        // Histórico/envio para UM cliente só (não bulk) — segue aberto à mesma audiência de
        // `professional/clients/{id}` (qualquer membro ativo dono do relacionamento).
        Route::get('clients/{id}/message-history', [ClientMessageController::class, 'history']);
        Route::post('clients/{id}/messages', [ClientMessageController::class, 'store']);

        // Professional Dashboard Stats
        Route::get('dashboard/stats', [ProfessionalDashboardController::class, 'stats']);

        // Conta corrente do cliente — contrato docs/gap-simplesvet/specs/
        // 11-conta-corrente-do-cliente-spec.md.
        // `client-account.manage` (não `.view.any`) de propósito: `CompanyClientAccountPolicy::
        // viewAny()` é só OWNER — "relatório consolidado" nunca é o mesmo público de "ver
        // extrato de UM cliente" (regra de negócio 11, mesmo texto da spec). Não existe
        // permissão própria de "consolidado" no catálogo (item 22); reaproveita `.manage`
        // porque a população de owner-only é idêntica.
        Route::middleware('permission:client-account.manage')->group(function (): void {
            Route::get('dashboard/receivables-from-clients', [\App\Http\Controllers\Api\Finance\ClientBalanceReportController::class, 'dashboard']);
            Route::get('reports/client-balances', [\App\Http\Controllers\Api\Finance\ClientBalanceReportController::class, 'index']);
        });

        // Agenda semanal (fonte de verdade lida por `GET /api/public/booking/availability`,
        // `AvailabilityService`). "week" é segmento literal ANTES do apiResource — mesma
        // armadilha já documentada em "pending"/"walk-in" acima.
        Route::put('availability/week', [ProfessionalAvailabilityController::class, 'replaceWeek']);
        Route::apiResource('availability', ProfessionalAvailabilityController::class)
            ->except(['show'])
            ->parameters(['availability' => 'id']);

        // Bloqueios de agenda (férias, almoço, compromisso). "history" é segmento literal
        // ANTES do apiResource, mesma armadilha já documentada em "pending"/"walk-in" acima
        // (aqui não colide de fato, porque `->except(['show'])` não registra GET .../{id},
        // mas mantém o padrão do arquivo).
        Route::get('blocked-times/history', [ProfessionalBlockedTimeController::class, 'history']);
        Route::apiResource('blocked-times', ProfessionalBlockedTimeController::class)
            ->except(['show'])
            ->parameters(['blocked-times' => 'id']);

        // Grade recurso × hora e fila do dia — item 21 do backlog gap-simplesvet
        // (docs/gap-simplesvet/specs/21-agenda-escala-bloqueios-recursos-spec.md §Endpoints
        // sugeridos). Só leitura. `agenda.view.own`/`.view.any` já existem no catálogo (item
        // 22) e já são atribuídos por papel no seeder — faltava só o `->middleware(...)` aqui
        // (achado desta passada final).
        Route::middleware('permission:agenda.view.own|agenda.view.any')->group(function (): void {
            Route::get('agenda/day', [\App\Http\Controllers\Api\Professional\AgendaController::class, 'day']);
            Route::get('agenda/queue', [\App\Http\Controllers\Api\Professional\AgendaController::class, 'queue']);

            // Seletor de unidade do filtro `location_id` acima — achado do frontend, não
            // existia NENHUM endpoint de listagem de `Location` antes desta rodada.
            Route::get('locations', [\App\Http\Controllers\Api\Professional\LocationController::class, 'index']);
        });

        // BI: produtividade e vendas — item 20 do backlog gap-simplesvet
        // (docs/gap-simplesvet/specs/20-bi-produtividade-vendas-spec.md). `bi.view.own`/
        // `.view.any` já existem no catálogo (item 22) e já são atribuídos por papel no
        // seeder — diferente de `sales`/`commissions` acima, aqui dá para aplicar
        // `permission:` de verdade: rota nova, sem fixture legada dependendo do 403 não
        // acontecer. "própria × qualquer" continua não sendo decisão do middleware
        // (`App\Services\Insights\ProductivityAuthorization` decide).
        Route::prefix('insights')->middleware('permission:bi.view.own|bi.view.any')->group(function (): void {
            // Segmento literal ANTES do `{indicator}` — mesma armadilha de "lookup"/"pending"
            // já documentada em outras rotas deste arquivo.
            Route::get('productivity', [\App\Http\Controllers\Api\Insights\ProductivityController::class, 'index']);
            Route::get('{indicator}', [\App\Http\Controllers\Api\Insights\InsightsController::class, 'show'])
                ->whereIn('indicator', \App\Enums\InsightIndicator::values());
            Route::get('{indicator}/drill-down', [\App\Http\Controllers\Api\Insights\InsightsController::class, 'drillDown'])
                ->whereIn('indicator', \App\Enums\InsightIndicator::values());
            Route::get('{indicator}/export', [\App\Http\Controllers\Api\Insights\InsightsController::class, 'export'])
                ->whereIn('indicator', \App\Enums\InsightIndicator::values());
        });
        Route::get('me/productivity', [\App\Http\Controllers\Api\Insights\ProductivityController::class, 'me'])
            ->middleware('permission:bi.view.own|bi.view.any');

        Route::apiResource('dashboard-widgets', \App\Http\Controllers\Api\Insights\DashboardWidgetController::class)
            ->only(['index', 'store', 'destroy'])
            ->parameters(['dashboard-widgets' => 'id'])
            ->middleware('permission:bi.view.own|bi.view.any');
        Route::apiResource('favorite-indicators', \App\Http\Controllers\Api\Insights\FavoriteIndicatorController::class)
            ->only(['index', 'store', 'destroy'])
            ->parameters(['favorite-indicators' => 'id'])
            ->middleware('permission:bi.view.own|bi.view.any');
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

    // Pets perdidos — o menu onde o alerta fica de pe ate o tutor encerrar.
    // Aberto a tutor E profissional: os dois recebem a notificacao de raio, os
    // dois precisam do mesmo lugar para reve-la depois.
    Route::prefix('lost-pets')->group(function () {
        Route::get('/', [LostPetController::class, 'index']);
        Route::post('/{alertId}/found', [LostPetController::class, 'markFound']);
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

        // Laudo estruturado — contrato docs/gap-simplesvet/specs/16-modelos-exame-laudos-spec.md.
        Route::post('/{examId}/finalize', [ExamController::class, 'finalize']);
        Route::get('/{examId}/pdf', [ExamController::class, 'pdf']);
    });

    // Catálogo de exame + pedido de exame — contrato docs/gap-simplesvet/specs/
    // 16-modelos-exame-laudos-spec.md. Permissões: docs/gap-simplesvet/specs/
    // permissoes-catalogo.md (`exam-templates.view`/`.manage`).
    Route::get('exam-types', [ExamTypeController::class, 'index'])->middleware('permission:exam-templates.view');
    Route::post('exam-types', [ExamTypeController::class, 'store'])->middleware('permission:exam-templates.manage');
    Route::get('exam-types/{examType}', [ExamTypeController::class, 'show'])->middleware('permission:exam-templates.view');
    Route::put('exam-types/{examType}', [ExamTypeController::class, 'update'])->middleware('permission:exam-templates.manage');
    Route::delete('exam-types/{examType}', [ExamTypeController::class, 'destroy'])->middleware('permission:exam-templates.manage');

    Route::post('exam-requests', [ExamRequestController::class, 'store']);
    Route::get('exam-requests/{examRequest}/pdf', [ExamRequestController::class, 'pdf']);

    // Modelos de documento (atestado/termo/declaração) + documentos gerados + perfil legal
    // do profissional — contrato docs/gap-simplesvet/specs/
    // 15-modelos-documento-receituario-assinatura-spec.md. Permissões:
    // docs/gap-simplesvet/specs/permissoes-catalogo.md (`document-templates.view`/`.manage`).
    Route::get('document-templates', [DocumentTemplateController::class, 'index'])
        ->middleware('permission:document-templates.view');
    Route::post('document-templates', [DocumentTemplateController::class, 'store'])
        ->middleware('permission:document-templates.manage');
    Route::get('document-templates/{documentTemplate}', [DocumentTemplateController::class, 'show'])
        ->middleware('permission:document-templates.view');
    Route::put('document-templates/{documentTemplate}', [DocumentTemplateController::class, 'update'])
        ->middleware('permission:document-templates.manage');
    Route::delete('document-templates/{documentTemplate}', [DocumentTemplateController::class, 'destroy'])
        ->middleware('permission:document-templates.manage');

    Route::get('generated-documents', [GeneratedDocumentController::class, 'index']);
    Route::post('generated-documents', [GeneratedDocumentController::class, 'store']);
    Route::get('generated-documents/{generatedDocument}', [GeneratedDocumentController::class, 'show']);
    Route::get('generated-documents/{generatedDocument}/pdf', [GeneratedDocumentController::class, 'pdf']);

    Route::get('me/professional-profile', [ProfessionalLegalProfileController::class, 'show']);
    Route::put('me/professional-profile', [ProfessionalLegalProfileController::class, 'update']);
    Route::post('me/professional-profile/signature', [ProfessionalLegalProfileController::class, 'uploadSignature']);

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
