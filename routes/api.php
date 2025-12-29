<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\Api\AiBusinessController;
use App\Http\Controllers\Api\AiBusinessInsightsController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\PetController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ProfessionalDashboardController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\ExamController;
use App\Http\Controllers\Api\HospitalizationController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\MedicalRecordController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PetCardController;
use App\Http\Controllers\Api\VaccinationController;
use App\Http\Controllers\Api\PrescriptionController;
use App\Http\Controllers\Api\ProfessionalClientController;
use App\Http\Controllers\Api\Public\BookingController;
use App\Http\Controllers\Api\Public\ProfessionalController;
use App\Http\Controllers\RegistrationDraftController;
use App\Http\Controllers\Api\Public\SearchController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SurgeryController;
use App\Http\Controllers\Api\VideoConsultationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\RegistrationCompletionController;
use App\Http\Middleware\AdminMiddleware;
use Illuminate\Support\Facades\Route;

// Public routes - Professional Search & Discovery
Route::prefix('public')->group(function () {
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/nearby', [SearchController::class, 'nearby']);
    Route::get('/categories', [SearchController::class, 'categories']);
    Route::get('/professionals/{id}', [ProfessionalController::class, 'show']);

    // Pet Digital Card (public access)
    Route::get('/pet-card/{publicId}', [PetCardController::class, 'show']);

    // Booking endpoints (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/booking/availability', [BookingController::class, 'availability']);
        Route::post('/booking', [BookingController::class, 'book']);
        Route::post('/booking/{id}/cancel', [BookingController::class, 'cancel']);
        Route::post('/booking/{id}/reschedule', [BookingController::class, 'reschedule']);
        Route::post('/waitlist', [BookingController::class, 'joinWaitlist']);
    });
});

// Public routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
Route::post('/verify-email/{token}', [\App\Http\Controllers\EmailVerificationController::class, 'verify']);
Route::post('/resend-verification', [\App\Http\Controllers\EmailVerificationController::class, 'resend']);

// Public Search & Discovery
Route::prefix('public')->group(function () {
    Route::get('/search', [SearchController::class, 'search']);
    Route::get('/featured', [SearchController::class, 'featured']);
    Route::get('/professionals/{id}', [ProfessionalController::class, 'show']);
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);

    // Registration Completion
    Route::post('/register/complete-tutor', [RegistrationCompletionController::class, 'completeTutor']);
    Route::post('/register/complete-professional', [RegistrationCompletionController::class, 'completeProfessional']);
    Route::post('/register/complete-company', [RegistrationCompletionController::class, 'completeCompany']);

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

    // Dashboard stats for tutor
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);

    // Pet routes
    Route::apiResource('pets', PetController::class);

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

        // Clients
        Route::apiResource('clients', ProfessionalClientController::class);
        Route::get('clients/{id}/pets', [ProfessionalClientController::class, 'pets']);

        // Professional Dashboard Stats
        Route::get('dashboard/stats', [ProfessionalDashboardController::class, 'stats']);
    });

    // AI Guardian Route
    Route::post('/ai/analyze', [AiController::class, 'analyze']);

    // AI Business Insight Route
    Route::post('/ai/business-analyze', [AiBusinessController::class, 'analyze']);

    // AI Business Insights Dashboard
    Route::get('/professional/ai/insights', [AiBusinessInsightsController::class, 'generateInsights']);

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
        Route::post('/{id}/moderate', [ReviewController::class, 'moderate'])->middleware('admin');
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

    // Video Consultations
    Route::prefix('video-consultations')->group(function () {
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
    });
});
